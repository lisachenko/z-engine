<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\Type;

use Countable;
use FFI\CData;
use IteratorAggregate;
use ReflectionClass as NativeReflectionClass;
use Traversable;
use ZEngine\Core;
use ZEngine\Generated\Bucket;
use ZEngine\Generated\HashTable as HashTableStruct;
use ZEngine\Generated\zend_array;
use ZEngine\Generated\zend_function;
use ZEngine\Generated\zend_internal_function;
use ZEngine\Generated\zend_refcounted_h;
use ZEngine\Reflection\ReflectionValue;

/**
 * Class HashTable provides general access to the internal array objects, aka hash-table
 *
 * Memory ownership contract (see docs/long-running.md for the full model):
 *
 *  - The wrapper is always a BORROWED view over an engine-owned hashtable (no addref).
 *  - add() makes the ENGINE copy the source zval into its own bucket: the temporary
 *    container passed in stays with the caller and must still be released by the caller
 *    (the bucket owns the payload reference that copy() took).
 *  - find() and iteration yield BORROWED ReflectionValue wrappers over bucket zvals: they
 *    are valid only until the bucket is deleted or the table is rehashed, and reading them
 *    never changes refcounts.
 *  - delete() lets the engine destructor release the bucket payload - nothing on the PHP
 *    side may release it again.
 *
 * struct _zend_array {
 *     zend_refcounted_h gc;
 *     union {
 *         struct {
 *             zend_uchar    flags;
 *             zend_uchar    _unused;
 *             zend_uchar    nIteratorsCount;
 *             zend_uchar    _unused2;
 *         } v;
 *         uint32_t flags;
 *     } u;
 *     uint32_t          nTableMask;
 *     Bucket           *arData;
 *     uint32_t          nNumUsed;
 *     uint32_t          nNumOfElements;
 *     uint32_t          nTableSize;
 *     uint32_t          nInternalPointer;
 *     zend_long         nNextFreeElement;
 *     dtor_func_t       pDestructor;
 * };
 *
 * @implements IteratorAggregate<int|string, ReflectionValue>
 */
class HashTable implements IteratorAggregate, Countable, ReferenceCountedInterface
{
    use ReferenceCountedTrait;

    protected const int HASH_UPDATE          = (1 << 0);
    protected const int HASH_ADD             = (1 << 1);
    protected const int HASH_UPDATE_INDIRECT = (1 << 2);
    protected const int HASH_ADD_NEW         = (1 << 3);
    protected const int HASH_ADD_NEXT        = (1 << 4);

    /**
     * Corresponds to the HASH_FLAG_PACKED flag in zend_types.h
     */
    protected const int HASH_FLAG_PACKED = (1 << 2);

    /**
     * @see zend_hash.c:uninitialized_bucket - two uint32_t slots holding HT_INVALID_IDX
     */
    private const int HT_INVALID_IDX = 0xFFFFFFFF;

    /**
     * Process-lifetime stand-in for the engine's static uninitialized_bucket
     */
    private static ?CData $uninitializedBucket = null;

    /**
     * @var HashTableStruct Typed view of the wrapped engine hashtable; the runtime value
     *                      is the raw FFI\CData handle (see stubs/zend-engine-structs.php)
     */
    protected object $pointer;

    /**
     * Creates a NEW empty engine-compatible hashtable OWNED by this wrapper
     *
     * Field-for-field port of zend_hash.c:_zend_hash_init_int(ht, HT_MIN_SIZE, NULL,
     * persistent): the struct starts HASH_FLAG_UNINITIALIZED and the engine real-inits
     * it on the first insert, choosing the data allocator from the GC flags (request
     * memory here, malloc for the PersistentHashTable subclass). pDestructor is NULL:
     * the writer owns every stored payload. Release the table via destroy(); a table
     * never destroyed is reclaimed by the request allocator at request end (and reported
     * by the debug-build leak gate).
     *
     * A BORROWED view over an engine-owned table is a different construction: fromCData().
     */
    public function __construct()
    {
        $memory  = Core::trackedNew(HashTableStruct::class, static::isPersistentAllocation());
        $pointer = Core::cast(HashTableStruct::class, Core::addr($memory));

        $gcHeader   = $pointer->gc;
        $gcInfo     = $gcHeader->u;
        $flagsUnion = $pointer->u;

        $gcHeader->refcount        = 1;
        $gcInfo->type_info         = static::gcTypeInfo();
        $flagsUnion->flags         = Core::engineConstant('HASH_FLAG_UNINITIALIZED');
        $pointer->nTableMask       = Core::engineConstant('HT_MIN_MASK');
        $pointer->arData           = self::uninitializedBucketData();
        $pointer->nNumUsed         = 0;
        $pointer->nNumOfElements   = 0;
        $pointer->nTableSize       = Core::engineConstant('HT_MIN_SIZE');
        $pointer->nInternalPointer = 0;
        $pointer->nNextFreeElement = PHP_INT_MIN;
        $pointer->pDestructor      = null;

        $this->pointer = $pointer;
    }

    /**
     * Wraps an existing engine-owned hashtable (BORROWED, never released by the wrapper)
     *
     * The caller guarantees the pointed table stays alive for the wrapper lifetime -
     * the standard borrowed construction of the framework (docs/long-running.md).
     *
     * The engine declares HashTable as a typedef of struct _zend_array, so both generated
     * views name the very same struct and either one is accepted here.
     *
     * @param CData|HashTableStruct|zend_array $hashInstance
     */
    public static function fromCData(object $hashInstance): static
    {
        $table = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
        \assert($table instanceof static);
        /** @var HashTableStruct $hashInstance Narrowed to the stub view at the owning boundary */
        $table->pointer = $hashInstance;

        return $table;
    }

    /**
     * Dismantles an OWNED table completely: engine data block first, then the struct
     *
     * Only for tables minted by the constructor - never call it on a borrowed fromCData()
     * view. Stored payloads are not touched (pDestructor is NULL by construction), so
     * whoever wrote a value into the table still owns it. Sealed persistent tables are
     * handled by the PersistentHashTable override.
     */
    public function destroy(): void
    {
        Core::call('zend_hash_destroy', $this->pointer);

        // The engine-grown data block is gone; release the struct through the
        // tracked-block registry so the right allocator is guaranteed
        Core::untrackAndFree($this->pointer);
    }

    /**
     * Allocation class for the constructor: request memory for plain owned tables
     */
    protected static function isPersistentAllocation(): bool
    {
        return false;
    }

    /**
     * GC header for the constructor: an ordinary collectable request array
     */
    protected static function gcTypeInfo(): int
    {
        return Core::engineConstant('GC_ARRAY');
    }

    /**
     * Returns arData pointing right past the shared sentinel, as HT_SET_DATA_ADDR does
     *
     * The sentinel block is shared by every table z-engine initializes in the
     * HASH_FLAG_UNINITIALIZED state, persistent and request-lifetime alike: the engine
     * never writes through (or frees) arData while the flag is set, so one immortal
     * process-wide block safely backs them all.
     *
     * @see zend_types.h:HT_SET_DATA_ADDR/HT_HASH_SIZE - for HT_MIN_MASK the hash part
     *      occupies two uint32_t slots, so arData points 8 bytes past the block start
     * @internal also used by ClassSpecializer to initialize the class-entry tables
     *
     * @return Bucket
     */
    public static function uninitializedBucketData(): object
    {
        if (self::$uninitializedBucket === null) {
            $block = Core::trackedNew('uint32_t[2]', true);
            // Both slots hold HT_INVALID_IDX, written as one machine-order byte image
            Core::memcpy($block, pack('L2', self::HT_INVALID_IDX, self::HT_INVALID_IDX), 8);

            self::$uninitializedBucket = $block;
        }

        return Core::pointerAtAddress(Bucket::class, Core::addressOf(self::$uninitializedBucket) + 8);
    }

    /**
     * Retrieve an external iterator
     *
     * @return Traversable<int|string, ReflectionValue> Borrowed views over the bucket zvals
     */
    #[\Override]
    public function getIterator(): Traversable
    {
        $iterator = function () {
            $isPacked = (bool) ($this->pointer->u->flags & self::HASH_FLAG_PACKED);
            $numUsed  = $this->pointer->nNumUsed;
            // Initialized tables always carry a data block (the shared sentinel at minimum)
            assert($this->pointer->arPacked !== null && $this->pointer->arData !== null);
            $arPacked = new StructArray($this->pointer->arPacked, $numUsed);
            $arData   = new StructArray($this->pointer->arData, $numUsed);
            for ($index = 0; $index < $numUsed; $index++) {
                if ($isPacked) {
                    // Since PHP 8.2 packed arrays store plain zvals with
                    // implicit integer keys instead of Bucket structures
                    $value = $arPacked[$index];
                    if ($value->u1->v->type === ReflectionValue::IS_UNDEF) {
                        continue;
                    }
                    yield $index => ReflectionValue::fromValueEntry($value);
                } else {
                    $item = $arData[$index];
                    if ($item->val->u1->v->type === ReflectionValue::IS_UNDEF) {
                        continue;
                    }
                    if ($item->key !== null) {
                        $key = StringEntry::fromCData($item->key)->getStringValue();
                    } else {
                        // Integer-keyed bucket: the numeric key lives in the hash field
                        $key = $item->h;
                    }
                    yield $key => ReflectionValue::fromValueEntry($item->val);
                }
            }
        };

        return $iterator();
    }

    /**
     * Returns the number of live elements stored in the table
     *
     * This is the engine's own nNumOfElements, ie the number of entries iteration yields:
     * deleted buckets still occupying a slot (nNumUsed) are not counted.
     */
    #[\Override]
    public function count(): int
    {
        // The engine field is an uint32_t; the clamp only states that for the analyser
        return max($this->pointer->nNumOfElements, 0);
    }

    /**
     * Returns the engine's own key block of the bucket stored under the given key
     *
     * The result is a BORROWED view over the zend_string the table itself points at, which
     * is how the storage class of a key is inspected (interned, permanent, persistent) -
     * unlike a StringEntry built from the PHP key, which is an unrelated copy.
     *
     * @return StringEntry|null The bucket key block, or null when no such string key exists
     * @internal
     */
    public function findKeyEntry(string $key): ?StringEntry
    {
        $bucket = $this->findBucket($key);
        if ($bucket === null) {
            return null;
        }
        $keyBlock = $bucket->key;
        // findBucket() only ever returns string-keyed buckets
        assert($keyBlock !== null);

        return StringEntry::fromCData($keyBlock);
    }

    /**
     * Swaps the key block of one bucket for an equivalent zend_string
     *
     * Only the bucket's key POINTER is rewritten: the stored value, the bucket hash and the
     * table's collision index stay as they are, so the replacement MUST carry the same
     * content as the current key (enforced below) - this is a storage-class swap of a key
     * block, never a rename. No refcounting happens on either side: the caller keeps
     * ownership of the new block and must keep it alive at least as long as the table
     * (interned and persistent-interned blocks are never released by the engine).
     *
     * Packed tables carry plain zvals with implicit integer keys and are reported as a miss.
     *
     * @param string      $currentKey Content of the key whose block is replaced
     * @param StringEntry $newKey     Replacement block, same content, outliving the table
     *
     * @return bool Whether a bucket key was actually rewritten
     * @internal
     */
    public function replaceKey(string $currentKey, StringEntry $newKey): bool
    {
        if ($newKey->getStringValue() !== $currentKey) {
            throw new \LogicException(
                'A hashtable key block can only be replaced by one with identical content: '
                . "\"{$currentKey}\" cannot become \"{$newKey->getStringValue()}\" without rehashing",
            );
        }
        $bucket = $this->findBucket($currentKey);
        if ($bucket === null) {
            return false;
        }
        $bucket->key = $newKey->getRawValue();

        return true;
    }

    /**
     * Returns the string-keyed bucket holding the given key, or null when there is none
     *
     * The engine's own bucket lookup (zend_hash_find_bucket) is inline-only and not
     * exported, so the walk is done here - the single place in the framework that knows
     * how buckets are laid out.
     *
     * @return Bucket|null
     */
    private function findBucket(string $key): ?object
    {
        // Packed tables store plain zvals with implicit integer keys - no string keys at all
        if (($this->pointer->u->flags & self::HASH_FLAG_PACKED) !== 0) {
            return null;
        }
        $numUsed = $this->pointer->nNumUsed;
        $arData  = $this->pointer->arData;
        // Initialized tables always carry a data block (the shared sentinel at minimum)
        assert($arData !== null);
        $buckets = new StructArray($arData, $numUsed);
        for ($index = 0; $index < $numUsed; $index++) {
            $bucket   = $buckets[$index];
            $keyBlock = $bucket->key;
            // Integer-keyed and deleted buckets carry no key block
            if ($keyBlock === null) {
                continue;
            }
            if (StringEntry::fromCData($keyBlock)->getStringValue() === $key) {
                return $bucket;
            }
        }

        return null;
    }

    /**
     * Performs search by key in the hashtable
     *
     * @param string $key Key to find
     *
     * @return ReflectionValue|null Value or null if not found
     */
    public function find(string $key): ?ReflectionValue
    {
        $stringEntry = new StringEntry($key);
        $pointer     = Core::call('zend_hash_find', $this->pointer, $stringEntry->getRawValue());

        if ($pointer !== null) {
            assert($pointer instanceof CData);
            $pointer = ReflectionValue::fromValueEntry($pointer);
        }

        return $pointer;
    }

    /**
     * Deletes a value by key from the hashtable
     *
     * @param string $key Key in the hash to delete
     * @internal
     */
    public function delete(string $key): void
    {
        $stringEntry = new StringEntry($key);
        $result      = Core::call('zend_hash_del', $this->pointer, $stringEntry->getRawValue());
        if ($result === Core::FAILURE) {
            throw TypeOperationException::cannotDeleteKey($key);
        }
    }

    /**
     * Removes a bucket WITHOUT running the table destructor over its payload
     *
     * Used to unpublish an entry whose payload must survive the removal (a shared
     * zend_function pointing into an immortal container, a class entry rehomed to
     * another table): the pDestructor is disabled for the duration of the delete so
     * the bucket removal releases nothing.
     *
     * @internal
     */
    public function deleteWithoutDestructor(string $key): void
    {
        $previousDestructor         = $this->pointer->pDestructor;
        $this->pointer->pDestructor = null;
        try {
            $this->delete($key);
        } finally {
            $this->pointer->pDestructor = $previousDestructor;
        }
    }

    /**
     * Deletes a value by integer key from the hashtable
     *
     * Same ownership contract as delete(): the engine destructor releases the bucket
     * payload, nothing on the PHP side may release it again.
     *
     * @param int $key Integer key in the hash to delete
     * @internal
     */
    public function deleteIndex(int $key): void
    {
        $result = Core::call('zend_hash_index_del', $this->pointer, $key);
        if ($result === Core::FAILURE) {
            throw TypeOperationException::cannotDeleteIndex($key);
        }
    }

    /**
     * Adds new value to the HashTable
     */
    public function add(string $key, ReflectionValue $value): void
    {
        $stringEntry = new StringEntry($key);
        // HASH_ADD (not HASH_ADD_NEW): the engine must actually check for an existing key
        // and return NULL on a duplicate. HASH_ADD_NEW skips that check in a non-debug
        // build and would silently insert a second bucket under the same key instead of
        // signalling the collision.
        $result = Core::call(
            'zend_hash_add_or_update',
            $this->pointer,
            $stringEntry->getRawValue(),
            $value->getRawValue(),
            self::HASH_ADD,
        );
        if ($result === null) {
            throw TypeOperationException::cannotAddKey($key);
        }
    }

    /**
     * Publishes a zend_function pointer into this function table and returns the pointer
     * that ends up stored in the freshly created bucket
     *
     * Both the class method table and the engine function table hold IS_PTR entries that
     * point at a zend_function: this is the shared publish path for
     * ReflectionClass::addMethod() and ReflectionFunction::addFunction(). The engine copies
     * the temporary zval container into its own bucket, so the container is released here;
     * pointer-level wrappers (ReflectionMethod/ReflectionFunction) must bind to the RETURNED
     * pointer (the one owned by the table), not to the passed structure.
     *
     * Keys are stored (and looked up) lowercased, matching how the engine keys function and
     * method tables - the declared function_name keeps its original case for display.
     *
     * @param string                            $key         Function/method name (any case)
     * @param CData|zend_function|zend_internal_function $rawFunction zend_function pointer to publish
     *
     * @return zend_function|zend_internal_function The zend_function pointer stored in the table bucket
     * @internal
     */
    public function addFunctionEntry(string $key, object $rawFunction): object
    {
        $lowerKey = strtolower($key);

        // The engine hashtable copies the zval into its own bucket, so the temporary
        // container exists only for the duration of this call and must be freed here
        $valueEntry = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $rawFunction);
        $this->add($lowerKey, $valueEntry);
        $valueEntry->release();

        // Return the pointer stored in the table (not the passed structure) so pointer-level
        // wrapper APIs like redefine() operate on the entry the engine will actually dispatch
        $storedEntry = $this->find($lowerKey);
        if ($storedEntry === null) {
            throw TypeOperationException::functionNotPublished($key);
        }

        return $storedEntry->getRawFunction();
    }

    /**
     * Performs search by integer key in the hashtable
     *
     * Same borrowing contract as find(): the returned wrapper is valid until the bucket
     * is deleted or the table is rehashed, and reading it never changes refcounts.
     *
     * @return ReflectionValue|null Value or null if not found
     */
    public function findIndex(int $key): ?ReflectionValue
    {
        $pointer = Core::call('zend_hash_index_find', $this->pointer, $key);

        if ($pointer !== null) {
            assert($pointer instanceof CData);
            $pointer = ReflectionValue::fromValueEntry($pointer);
        }

        return $pointer;
    }

    /**
     * Adds new value with an integer key to the HashTable
     *
     * Same ownership contract as add(): the engine copies the source zval into its own
     * bucket, the temporary container stays with the caller.
     */
    public function addIndex(int $key, ReflectionValue $value): void
    {
        $result = Core::call(
            'zend_hash_index_add_or_update',
            $this->pointer,
            $key,
            $value->getRawValue(),
            self::HASH_ADD_NEW,
        );
        if ($result === null) {
            throw TypeOperationException::cannotAddIndex($key);
        }
    }

    /**
     * Returns raw C value entry (HashTable *)
     *
     * @return HashTableStruct
     * @internal
     */
    public function getRawValue(): object
    {
        return $this->pointer;
    }

    public function __debugInfo()
    {
        return iterator_to_array($this->getIterator());
    }

    /**
     * @inheritDoc
     *
     * @return zend_refcounted_h
     */
    protected function getGC(): object
    {
        return $this->pointer->gc;
    }
}
