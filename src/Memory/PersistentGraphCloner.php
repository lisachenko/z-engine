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

namespace ZEngine\Memory;

use FFI\CData;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\ObjectEntry;
use ZEngine\Type\PersistentHashTable;
use ZEngine\Type\PersistentObjectFactory;
use ZEngine\Type\StringEntry;

/**
 * Deep persistent clone of an arbitrary object graph, cycle- and DAG-aware
 *
 * Single-use: one instance persists exactly one graph. The work happens in two strictly
 * separated phases:
 *
 *  1. VALIDATION walks the source graph and enforces the supported-type matrix
 *     (docs/persistent-heap.md) with typed exceptions. It allocates nothing persistent,
 *     so a rejected graph leaves no trace. A seen-set keyed by object identity makes the
 *     walk terminate on cycles.
 *  2. CLONING walks the raw engine values and mints the persistent copy. Identity maps
 *     keyed by source address guarantee that every source object/array/string content is
 *     cloned exactly ONCE: shared DAG nodes stay shared in the clone, and a back-edge
 *     resolves to the already-recorded clone pointer instead of recursing (the map entry
 *     is recorded BEFORE the node's own edges are followed).
 *
 * Value conversion rules (why the clone can never dangle):
 *
 *  - scalars/null/undef: the byte copy of the zval is already self-contained;
 *  - strings: replaced by persistent interned blocks (StringEntry::persistentInterned),
 *    deduplicated by content; slots store them NON-refcounted, interned-style;
 *  - arrays: replaced by sealed PersistentHashTable copies (immutable, copy-on-write for
 *    userland); element order and string/integer keys are preserved; key strings are
 *    minted through the same tracked string pool so eviction can free them;
 *  - objects: replaced by refcount-pinned persistent clones (PersistentObjectFactory)
 *    whose slots are recursively rewritten in place.
 *
 * @internal used by PersistentHeap::put()
 */
final class PersistentGraphCloner
{
    /**
     * Source objects already validated, keyed by spl_object_id (cycle terminator)
     *
     * @var array<int, true>
     */
    private array $seenSourceObjects = [];

    /**
     * Source arrays already validated, keyed by zend_array address
     *
     * @var array<int, true>
     */
    private array $seenSourceArrays = [];

    /**
     * Source zend_object address => persistent clone (DAG/cycle identity map)
     *
     * @var array<int, CData>
     */
    private array $objectMap = [];

    /**
     * Source zend_array address => persistent table pointer (sharing preserved)
     *
     * @var array<int, CData>
     */
    private array $arrayMap = [];

    /**
     * String content => minted persistent interned entry (content deduplication)
     *
     * @var array<string, StringEntry>
     */
    private array $stringPool = [];

    /** @var list<CData> */
    private array $objects = [];

    /** @var list<string> */
    private array $classNames = [];

    /** @var list<int> */
    private array $classSizes = [];

    /** @var list<CData> */
    private array $strings = [];

    /** @var list<CData> */
    private array $arrays = [];

    private int $bytes = 0;

    /**
     * Cached zval type flags for a refcounted+collectable payload (IS_OBJECT_EX shape)
     */
    private readonly int $objectTypeFlags;

    public function __construct()
    {
        $refcounted = Core::engineConstant('IS_TYPE_REFCOUNTED') | Core::engineConstant('IS_TYPE_COLLECTABLE');

        $this->objectTypeFlags = $refcounted << Core::engineConstant('Z_TYPE_FLAGS_SHIFT');
    }

    /**
     * Validates the whole reachable graph and mints its persistent clone
     *
     * @param object $root Root of the graph to persist
     *
     * @throws UnsupportedGraphElementException when any reachable value is outside the matrix
     */
    public function persist(object $root): PersistedGraph
    {
        // Phase 1: full validation before the first persistent byte is allocated
        $this->validateObject($root, '$root');

        // Phase 2: clone from the raw engine values ($root keeps the source graph alive)
        $rootValue = new ReflectionValue($root);
        $rootClone = $this->cloneObject($rootValue->getRawObject());
        $rootValue->release();

        return new PersistedGraph(
            $rootClone,
            $this->objects,
            $this->classNames,
            $this->classSizes,
            $this->strings,
            $this->arrays,
            $this->bytes,
        );
    }

    /**
     * Enforces the supported-type matrix for one source object and recurses into its slots
     */
    private function validateObject(object $source, string $path): void
    {
        $objectId = spl_object_id($source);
        if (isset($this->seenSourceObjects[$objectId])) {
            return;
        }
        $this->seenSourceObjects[$objectId] = true;

        if ($source instanceof \Closure) {
            throw UnsupportedGraphElementException::closure($path);
        }

        $native    = new \ReflectionObject($source);
        $className = $native->getName();
        if ($native->isEnum()) {
            throw UnsupportedGraphElementException::enumCase($className, $path);
        }
        if ($native->isInternal()) {
            throw UnsupportedGraphElementException::internalClass($className, $path);
        }

        $entry     = ObjectEntry::weakFor($source);
        $rawObject = $entry->getRawValue();
        if ($entry->isLazy()) {
            throw UnsupportedGraphElementException::lazyObject($className, $path);
        }
        if (!PersistentObjectFactory::usesStandardHandlers($rawObject)) {
            throw UnsupportedGraphElementException::customHandlers($className, $path);
        }
        if (($rawObject->ce->ce_flags & Core::ZEND_ACC_USE_GUARDS) !== 0) {
            throw UnsupportedGraphElementException::propertyGuards($className, $path);
        }
        foreach ($native->getProperties() as $property) {
            if (!$property->isDefault()) {
                throw UnsupportedGraphElementException::dynamicProperties($className, $path);
            }
        }

        $slotCount = $rawObject->ce->default_properties_count;
        $tableBase = Core::cast('zval *', Core::addr($rawObject->properties_table[0]));
        for ($index = 0; $index < $slotCount; $index++) {
            $this->validateValue(Core::addr($tableBase[$index]), "{$path}({$className})->slot#{$index}");
        }
    }

    /**
     * Validates one source zval (given as zval*) against the supported-type matrix
     */
    private function validateValue(CData $zval, string $path): void
    {
        $type = $zval->u1->v->type;
        switch ($type) {
            case ReflectionValue::IS_UNDEF:
            case ReflectionValue::IS_NULL:
            case ReflectionValue::IS_FALSE:
            case ReflectionValue::IS_TRUE:
            case ReflectionValue::IS_LONG:
            case ReflectionValue::IS_DOUBLE:
            case ReflectionValue::IS_STRING:
                return;

            case ReflectionValue::IS_ARRAY:
                $this->validateArray($zval->value->arr, $path);

                return;

            case ReflectionValue::IS_OBJECT:
                // Materialize the child as a live PHP object to reuse reflection checks;
                // the temporary reference is dropped when $child leaves this scope
                ReflectionValue::fromValueEntry($zval)->getNativeValue($child);
                assert(is_object($child));
                $this->validateObject($child, $path);

                return;

            case ReflectionValue::IS_RESOURCE:
                throw UnsupportedGraphElementException::resource($path);

            case ReflectionValue::IS_REFERENCE:
                throw UnsupportedGraphElementException::reference($path);

            default:
                throw UnsupportedGraphElementException::unsupportedType(ReflectionValue::name($type), $path);
        }
    }

    /**
     * Validates every element of a source array (shared arrays are walked once)
     */
    private function validateArray(CData $sourceArray, string $path): void
    {
        $address = Core::addressOf($sourceArray);
        if (isset($this->seenSourceArrays[$address])) {
            return;
        }
        $this->seenSourceArrays[$address] = true;

        $this->walkArray($sourceArray, function (int|string $key, CData $value) use ($path): void {
            $this->validateValue($value, "{$path}[{$key}]");
        });
    }

    /**
     * Mints (or returns the already minted) persistent clone of one source object
     *
     * The identity map entry is recorded BEFORE the slots are rewritten, so a back-edge
     * (cycle) or a shared DAG node resolves to the same clone instead of recursing.
     */
    private function cloneObject(CData $sourceObject): CData
    {
        $address = Core::addressOf($sourceObject);
        if (isset($this->objectMap[$address])) {
            return $this->objectMap[$address];
        }

        $clone = PersistentObjectFactory::persistentClone($sourceObject);
        // The byte-copied handle belongs to the source in the CURRENT request; the clone
        // receives a fresh handle at every re-attachment (ObjectStore::put)
        $clone->handle = 0;

        $this->objectMap[$address] = $clone;

        $classEntry = $sourceObject->ce;
        $objectSize = ReflectionClass::getObjectSize($classEntry);

        $this->objects[]    = $clone;
        $this->classNames[] = strtolower(StringEntry::fromCData($classEntry->name)->getStringValue());
        $this->classSizes[] = $objectSize;
        $this->bytes += $objectSize;

        $slotCount = $classEntry->default_properties_count;
        $tableBase = Core::cast('zval *', Core::addr($clone->properties_table[0]));
        for ($index = 0; $index < $slotCount; $index++) {
            $this->rewriteValue(Core::addr($tableBase[$index]));
        }

        return $clone;
    }

    /**
     * Rewrites one clone-owned zval in place: request-lifetime payload pointers are
     * replaced by persistent ones, scalar byte copies are left untouched
     */
    private function rewriteValue(CData $zval): void
    {
        $type = $zval->u1->v->type;
        if ($type === ReflectionValue::IS_STRING) {
            $content = StringEntry::fromCData($zval->value->str)->getStringValue();

            $zval->value->str = $this->persistString($content)->getRawValue();
            // Interned-style payload: consumers copy the pointer without refcounting
            $zval->u1->type_info = ReflectionValue::IS_STRING;
        } elseif ($type === ReflectionValue::IS_ARRAY) {
            $zval->value->arr = $this->cloneArray($zval->value->arr);
            // Immutable payload: copy-on-write into request memory on mutation
            $zval->u1->type_info = ReflectionValue::IS_ARRAY;
        } elseif ($type === ReflectionValue::IS_OBJECT) {
            $zval->value->obj = $this->cloneObject($zval->value->obj);
            // Standard refcounted object shape: alias churn lands on the pinned counter,
            // and GC_NOT_COLLECTABLE in the clone header keeps the collector away
            $zval->u1->type_info = ReflectionValue::IS_OBJECT | $this->objectTypeFlags;
        }
    }

    /**
     * Mints (or returns the already minted) sealed persistent copy of one source array
     */
    private function cloneArray(CData $sourceArray): CData
    {
        $address = Core::addressOf($sourceArray);
        if (isset($this->arrayMap[$address])) {
            return $this->arrayMap[$address];
        }

        $table    = PersistentHashTable::create();
        $rawTable = $table->getRawValue();

        // Record the mapping before filling: an element may reach this very array again
        // through an object cycle
        $this->arrayMap[$address] = $rawTable;
        $this->arrays[]           = $rawTable;
        $this->bytes += Core::sizeof(Core::type('HashTable'));

        $zvalSize = Core::sizeof(Core::type('zval'));
        $this->walkArray($sourceArray, function (int|string $key, CData $value) use ($table, $zvalSize): void {
            // Start from a byte copy of the source element, then rewrite it in place;
            // the engine copies the fixed-up bytes into its own bucket on insert
            $element = Core::new('zval');
            Core::memcpy($element, $value[0], $zvalSize);
            $elementPointer = Core::addr($element);

            $this->rewriteValue($elementPointer);

            $borrowed = ReflectionValue::fromValueEntry($elementPointer);
            if (is_int($key)) {
                $table->addIndex($key, $borrowed);
            } else {
                $table->addInterned($this->persistString($key), $borrowed);
            }
        });

        // Interned-style sealing: non-refcounted in zvals, copy-on-write on userland writes
        $table->markImmutable();

        return $rawTable;
    }

    /**
     * Mints (or reuses) a persistent interned string block for the given content
     */
    private function persistString(string $content): StringEntry
    {
        if (isset($this->stringPool[$content])) {
            return $this->stringPool[$content];
        }

        $entry    = StringEntry::persistentInterned($content);
        $rawValue = $entry->getRawValue();
        assert($rawValue instanceof CData);

        $this->stringPool[$content] = $entry;
        $this->strings[]            = $rawValue;
        $this->bytes += Core::type('zend_string')->getStructFieldOffset('val') + strlen($content) + 1;

        return $entry;
    }

    /**
     * Iterates the live elements of a source zend_array, preserving string AND integer
     * keys (unlike HashTable::getIterator, which drops integer keys of hashed tables)
     *
     * @param \Closure(int|string, CData): void $visitor Receives the key and a zval* view
     */
    private function walkArray(CData $sourceArray, \Closure $visitor): void
    {
        $isPacked = ($sourceArray->u->flags & Core::engineConstant('HASH_FLAG_PACKED')) !== 0;
        $numUsed  = $sourceArray->nNumUsed;

        for ($index = 0; $index < $numUsed; $index++) {
            if ($isPacked) {
                $value = Core::addr($sourceArray->arPacked[$index]);
                if ($value->u1->v->type === ReflectionValue::IS_UNDEF) {
                    continue;
                }
                $visitor($index, $value);
            } else {
                $bucket = Core::addr($sourceArray->arData[$index]);
                $value  = Core::addr($bucket->val);
                if ($value->u1->v->type === ReflectionValue::IS_UNDEF) {
                    continue;
                }
                $key = $bucket->key !== null
                    ? StringEntry::fromCData($bucket->key)->getStringValue()
                    : $bucket->h;
                $visitor($key, $value);
            }
        }
    }
}
