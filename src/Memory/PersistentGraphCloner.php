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
use ZEngine\Generated\HashTable as HashTableStruct;
use ZEngine\Generated\zend_array;
use ZEngine\Generated\zend_object;
use ZEngine\Generated\zend_string;
use ZEngine\Generated\zval;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\HashTable;
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

    /**
     * @param Allocator|null $allocator Source of every persistent byte this cloner mints -
     *                                  object clones, interned string blocks and table
     *                                  structs alike. Null keeps each primitive on its own
     *                                  historical default (z-engine's malloc-backed FFI
     *                                  allocator), which is not the same allocator for all
     *                                  three: strings are minted untracked, the rest tracked.
     */
    public function __construct(private readonly ?Allocator $allocator = null)
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

        $entry = ObjectEntry::weakFor($source);
        if ($entry->isLazy()) {
            throw UnsupportedGraphElementException::lazyObject($className, $path);
        }
        // The handler-block check is pointer identity against std_object_handlers, the one
        // fact about the object no typed accessor can express
        if (!PersistentObjectFactory::usesStandardHandlers($entry->getRawValue())) {
            throw UnsupportedGraphElementException::customHandlers($className, $path);
        }
        if (($entry->getClass()->getFlags() & Core::ZEND_ACC_USE_GUARDS) !== 0) {
            throw UnsupportedGraphElementException::propertyGuards($className, $path);
        }
        foreach ($native->getProperties() as $property) {
            if (!$property->isDefault()) {
                throw UnsupportedGraphElementException::dynamicProperties($className, $path);
            }
        }

        $slotCount = $entry->getClass()->getDefaultPropertiesCount();
        for ($index = 0; $index < $slotCount; $index++) {
            $this->validateValue($entry->getPropertySlot($index), "{$path}({$className})->slot#{$index}");
        }
    }

    /**
     * Validates one source value against the supported-type matrix
     */
    private function validateValue(ReflectionValue $value, string $path): void
    {
        $type = $value->getBaseType();
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
                $this->validateArray($value->getRawArray(), $path);

                return;

            case ReflectionValue::IS_OBJECT:
                // Materialize the child as a live PHP object to reuse reflection checks;
                // the temporary reference is dropped when $child leaves this scope
                $value->getNativeValue($child);
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
     *
     * @param CData|zend_array $sourceArray The engine value the source zval points at
     */
    private function validateArray(object $sourceArray, string $path): void
    {
        $address = Core::addressOf($sourceArray);
        if (isset($this->seenSourceArrays[$address])) {
            return;
        }
        $this->seenSourceArrays[$address] = true;

        foreach (HashTable::fromCData($sourceArray) as $key => $element) {
            $this->validateValue($element, "{$path}[{$key}]");
        }
    }

    /**
     * Mints (or returns the already minted) persistent clone of one source object
     *
     * The identity map entry is recorded BEFORE the slots are rewritten, so a back-edge
     * (cycle) or a shared DAG node resolves to the same clone instead of recursing.
     *
     * @param CData|zend_object $sourceObject The engine value the source zval points at
     * @return \FFI\CData
     */
    private function cloneObject(object $sourceObject): object
    {
        $address = Core::addressOf($sourceObject);
        if (isset($this->objectMap[$address])) {
            return $this->objectMap[$address];
        }

        $clone      = PersistentObjectFactory::persistentClone($sourceObject, $this->allocator);
        $cloneEntry = ObjectEntry::fromCData($clone);
        // The byte-copied handle belongs to the source in the CURRENT request; the clone
        // receives a fresh handle at every re-attachment (ObjectStore::put)
        $cloneEntry->setHandle(0);

        $this->objectMap[$address] = $clone;

        $sourceClass = ObjectEntry::fromCData($sourceObject)->getClass();
        $objectSize  = ReflectionClass::getObjectSize($sourceClass->getRawValue());

        $this->objects[]    = $clone;
        $this->classNames[] = strtolower($sourceClass->getName());
        $this->classSizes[] = $objectSize;
        $this->bytes += $objectSize;

        // The clone is a byte copy, so it still carries the source class entry: its slot
        // count is the live one for the whole cloning pass
        $slotCount = $cloneEntry->getClass()->getDefaultPropertiesCount();
        for ($index = 0; $index < $slotCount; $index++) {
            $this->rewriteValue($cloneEntry->getPropertySlot($index));
        }

        return $clone;
    }

    /**
     * Rewrites one clone-owned value in place: request-lifetime payload pointers are
     * replaced by persistent ones, scalar byte copies are left untouched
     *
     * Every replacement is written NON-REFCOUNTED (writeUncountedPayload): the persistent
     * blocks minted here are interned strings, sealed arrays and refcount-pinned objects,
     * which the engine copies by pointer and never releases.
     */
    private function rewriteValue(ReflectionValue $value): void
    {
        $type = $value->getBaseType();
        if ($type === ReflectionValue::IS_STRING) {
            $content = StringEntry::fromCData($value->getRawString())->getStringValue();

            // Interned-style payload: consumers copy the pointer without refcounting
            $this->writeUncountedPayload($value, ReflectionValue::IS_STRING, $this->persistString($content)->getRawValue());
        } elseif ($type === ReflectionValue::IS_ARRAY) {
            // Immutable payload: copy-on-write into request memory on mutation
            $this->writeUncountedPayload($value, ReflectionValue::IS_ARRAY, $this->cloneArray($value->getRawArray()));
        } elseif ($type === ReflectionValue::IS_OBJECT) {
            // Standard refcounted object shape: alias churn lands on the pinned counter,
            // and GC_NOT_COLLECTABLE in the clone header keeps the collector away
            $this->writeUncountedPayload(
                $value,
                ReflectionValue::IS_OBJECT | $this->objectTypeFlags,
                $this->cloneObject($value->getRawObject()),
            );
        }
    }

    /**
     * Writes a payload pointer together with its complete type_info word into a
     * clone-owned slot, WITHOUT any refcounting on either the previous or the new content
     *
     * The primitive behind the engine's non-refcounted value shapes: interned strings,
     * immutable (sealed) arrays and refcount-pinned persistent objects are copied around
     * by pointer and never addref'd or released. The whole u1.type_info word is written
     * so the slot carries the exact flags the shape requires.
     *
     * This is NOT an assignment: the previous content is overwritten in place, which is
     * only legal on slots this cloner minted itself (byte copies of source zvals, fresh
     * persistent clones) whose previous payload nobody has to release. That precondition
     * cannot be expressed as an API contract, which is why the write lives here as
     * cloner-private machinery instead of on ReflectionValue.
     *
     * @param int          $typeInfo Full type_info word: base type | (type flags << Z_TYPE_FLAGS_SHIFT)
     * @param CData|object $payload  Payload block; the runtime value is always CData
     */
    private function writeUncountedPayload(ReflectionValue $slot, int $typeInfo, object $payload): void
    {
        /** @var zval $zval Raw escape hatch: the slot is cloner-owned, see the docblock */
        $zval = $slot->getRawValue();
        // Every zend_value member is pointer-sized, so the void* member writes the payload
        // bytes for whichever shape the type_info word declares
        $zval->value->ptr    = Core::cast('void *', $payload);
        $zval->u1->type_info = $typeInfo;
    }

    /**
     * Mints (or returns the already minted) sealed persistent copy of one source array
     *
     * @param CData|zend_array $sourceArray The engine value the source zval points at
     * @return \FFI\CData
     */
    private function cloneArray(object $sourceArray): object
    {
        $address = Core::addressOf($sourceArray);
        if (isset($this->arrayMap[$address])) {
            return $this->arrayMap[$address];
        }

        $table    = new PersistentHashTable($this->allocator);
        $rawTable = $table->getRawValue();

        // Record the mapping before filling: an element may reach this very array again
        // through an object cycle
        $this->arrayMap[$address] = $rawTable;
        $this->arrays[]           = $rawTable;
        $this->bytes += Core::sizeOfType(HashTableStruct::class);

        $zvalSize = Core::sizeOfType(zval::class);
        foreach (HashTable::fromCData($sourceArray) as $key => $sourceElement) {
            // Start from a byte copy of the source element, then rewrite it in place;
            // the engine copies the fixed-up bytes into its own bucket on insert
            $element = Core::new('zval');
            Core::memcpy($element, $sourceElement->getRawValue(), $zvalSize);

            $borrowed = ReflectionValue::fromValueEntry(Core::addr($element));
            $this->rewriteValue($borrowed);

            if (is_int($key)) {
                $table->addIndex($key, $borrowed);
            } else {
                $table->addInterned($this->persistString($key), $borrowed);
            }
        }

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

        $entry = StringEntry::persistentInterned($content, $this->allocator);

        $this->stringPool[$content] = $entry;
        $this->strings[]            = $entry->getRawValue();
        $this->bytes += Core::offsetOfField(zend_string::class, 'val') + strlen($content) + 1;

        return $entry;
    }

}
