<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\ClassExtension\Hook;

use FFI\CData;
use ZEngine\Generated\HashTable as HashTableStruct;
use ZEngine\Generated\zend_object;
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\HashTable;
use ZEngine\Type\ObjectEntry;

/**
 * Receiving hook for the property HashTable used by (array), foreach, get_object_vars, GC
 *
 * Memory ownership contract (see docs/long-running.md for the full model):
 *
 *  - The engine expects get_properties to return a BORROWED HashTable that stays valid as
 *    long as the object does (zend_std_get_properties returns zobj->properties). handle()
 *    therefore anchors the table built from the user handler result into zobj->properties,
 *    exactly like rebuild_object_properties: the object owns one reference and releases it
 *    on destruction, the previous table (if any) loses the object's reference first.
 *  - proceed() materializes the borrowed table of the original handler into a PHP array
 *    (following IS_INDIRECT slots of declared properties) and releases nothing.
 *  - The user handler must not let exceptions escape and should return a freshly built
 *    array (a table shared with other PHP references must not be handed to the engine as
 *    object property storage).
 *  - Anchoring outlives the hook: an object consulted while the hook was installed keeps
 *    the last anchored table in zobj->properties after uninstall() (the standard handler
 *    serves an existing table as-is and never rebuilds it). Objects created after the
 *    uninstall behave exactly as before the hook was installed.
 *
 * Garbage collector interaction:
 *
 *  - zend_std_get_gc routes the cycle collector through get_properties whenever the field
 *    differs from zend_std_get_properties - straight into this hook's FFI trampoline. The
 *    collector calls get_gc while it is TRIAL-DELETING (refcounts of the scanned graph are
 *    temporarily decremented), so executing any PHP there corrupts engine state: debug
 *    builds abort with refcount-underflow/root-buffer assertions, release builds corrupt
 *    memory silently.
 *  - Constructing this hook therefore permanently redirects the get_gc field of the class
 *    handlers to ext/date's generic C implementation (*table = NULL, *n = 0, return
 *    zend_std_get_properties(obj)), which reads only standard zend_object fields and never
 *    consults get_properties. The collector then scans the table currently anchored in
 *    zobj->properties - exactly the property set this hook last reported - without ever
 *    entering userland. The redirect intentionally survives uninstall(): it is
 *    semantically equivalent to the standard behavior and keeps stale trampoline calls
 *    impossible.
 *  - Consequence (inherent to overriding get_properties, C extensions included): the
 *    collector's view of a hooked object is limited to that table. Declared properties
 *    appear either as non-refcounted IS_INDIRECT slots (never-consulted objects) or not
 *    at all (tables reported by this hook contain materialized values, not the slots),
 *    so object references held in declared properties are invisible to the collector and
 *    cycles running through them are reclaimed only at request shutdown by the object
 *    store - delayed collection, never corruption.
 */
final class GetPropertiesHook extends AbstractHook
{
    protected const HOOK_FIELD = 'get_properties';

    /**
     * Object instance
     *
     * @var zend_object Typed view of the engine handle; the runtime value is the raw
     *                  FFI\CData pointer (see stubs/zend-engine-structs.php)
     */
    protected object $object;

    /**
     * Guards against re-entering the user handler from inside itself
     */
    private bool $handling = false;

    /**
     * Cached pointer to ext/date's generic GC-safe get_gc C implementation
     */
    private static ?CData $gcSafeGetGc = null;

    /**
     * @param CData|\FFI $rawStructure Handlers container (zend_object_handlers*)
     *
     * @inheritDoc
     */
    public function __construct(\Closure $userHandler, $rawStructure)
    {
        parent::__construct($userHandler, $rawStructure);

        // Make the cycle collector unable to reach this hook's trampoline (see the class
        // docblock): the get_gc field of the handlers structure is redirected to a generic
        // C implementation before the get_properties field can ever be hooked
        assert($rawStructure instanceof CData);
        $rawStructure->get_gc = self::gcSafeGetGcPointer();
    }

    /**
     * typedef HashTable *(*zend_object_get_properties_t)(zend_object *object);
     *
     * @inheritDoc
     * @return HashTableStruct
     */
    #[\Override]
    public function handle(...$rawArguments): object
    {
        /** @var zend_object $object Narrowed to the stub view at the engine callback boundary */
        [$object]     = $rawArguments;
        $this->object = $object;

        // Serve the anchored table without userland for reentrant calls and - defense in
        // depth, the get_gc redirect already prevents it - while the collector is active
        if ($this->handling || gc_status()['running']) {
            return $this->currentEngineTable($object);
        }

        $this->handling = true;
        try {
            $result = ($this->userHandler)($this);
        } finally {
            $this->handling = false;
        }
        assert(is_array($result));

        $previousTable = $object->properties;
        if ($previousTable !== null) {
            assert($previousTable instanceof CData);
            // Keep the anchored table identity-stable while its content is unchanged:
            // foreach consults get_properties on every FE_FETCH and tracks its position
            // with a HashTableIterator bound to the table, so swapping in a fresh (even
            // identical) table would restart the iteration on each step
            $previousStruct = $previousTable[0];
            assert($previousStruct instanceof CData);
            $anchoredEntry = ReflectionValue::newEntry(ReflectionValue::IS_ARRAY, $previousStruct);
            $anchoredEntry->getNativeValue($anchoredArray);
            $anchoredEntry->release();
            if ($anchoredArray === $result) {
                return $previousTable;
            }
        }

        $refValue = new ReflectionValue($result);
        /** @var HashTableStruct $rawArray zend_array and HashTable are the same engine struct */
        $rawArray = $refValue->getRawArray();
        // Exactly one payload reference is handed over to the object below; the
        // temporary zval container is freed right away
        $refValue->transferReferenceOwnership();
        $refValue->release();

        // Anchor the table lifetime to the object exactly like rebuild_object_properties:
        // the previous table loses the object's reference (immutable tables such as the
        // shared empty array stay untouched), the new one is owned by the object and is
        // released by zend_object_std_dtor together with it
        if ($previousTable !== null) {
            (HashTable::fromCData($previousTable))->releaseReference();
        }
        $object->properties = $rawArray;

        return $rawArray;
    }

    /**
     * Returns an object instance
     */
    public function getObject(): object
    {
        $objectInstance = ObjectEntry::fromCData($this->object)->getNativeValue();

        return $objectInstance;
    }

    /**
     * Proceeds with the original handler and returns its table as a PHP array
     *
     * Declared properties are stored as IS_INDIRECT slots in the engine table: they are
     * followed (skipping uninitialized ones) so the result contains only plain values.
     * Note that non-public property keys keep their engine name mangling.
     *
     * @return array<array-key, mixed>
     */
    public function proceed(): array
    {
        $originalHandler = $this->getOriginalCallable();

        $rawArray = ($originalHandler)($this->object);
        if ($rawArray === null) {
            return [];
        }
        assert($rawArray instanceof CData);

        $table      = HashTable::fromCData($rawArray);
        $properties = [];
        foreach ($table as $key => $refValue) {
            if ($refValue->getType() === ReflectionValue::IS_INDIRECT) {
                $refValue = $refValue->getIndirectValue();
                if ($refValue->getType() === ReflectionValue::IS_UNDEF) {
                    continue;
                }
            }
            // Each value is materialized with its own reference owned by the built array;
            // the engine table itself stays borrowed from the object and is not released
            $refValue->getNativeValue($value);
            $properties[$key] = $value;
        }

        return $properties;
    }

    /**
     * Resolves ext/date's generic get_gc implementation, the only C-level get_gc in an
     * always-built extension that reads nothing beyond the standard zend_object fields:
     *
     *     static HashTable *date_object_get_gc(zend_object *object, zval **table, int *n)
     *     { *table = NULL; *n = 0; return zend_std_get_properties(object); }
     *
     * The pointer is harvested from a DateTime instance (the function itself is static
     * and not exported); the probe object is released right away, the C function address
     * has static lifetime.
     *
     * @return \FFI\CData
     */
    private static function gcSafeGetGcPointer(): object
    {
        if (self::$gcSafeGetGc === null) {
            $probe    = new \DateTime();
            $refValue = new ReflectionValue($probe);
            $handlers = $refValue->getRawObject()->handlers;
            assert($handlers instanceof CData);
            $getGc = $handlers->get_gc;
            assert($getGc instanceof CData);
            self::$gcSafeGetGc = $getGc;
            $refValue->release();
        }

        return self::$gcSafeGetGc;
    }

    /**
     * Returns the currently anchored property table without consulting userland
     *
     * Used for reentrant calls: the table anchored by the previous handle() run (or the
     * one the original handler builds into the object) is the engine's current view.
     *
     * @param zend_object $object
     * @return HashTableStruct
     */
    private function currentEngineTable(object $object): object
    {
        $properties = $object->properties;
        if ($properties !== null) {
            return $properties;
        }

        $originalHandler = $this->getOriginalCallable();
        /** @var HashTableStruct $table Narrowed to the stub view at the engine callback boundary */
        $table = ($originalHandler)($object);

        return $table;
    }
}
