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

namespace ZEngine\OpCache;

use FFI;
use FFI\CData;
use ZEngine\Core;
use ZEngine\Generated\Bucket;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\Type\HashTable;
use ZEngine\Type\StringEntry;

/**
 * A Reflection-style handle over the relocated zend_persistent_script of a
 * cache binary: it mirrors the native `ReflectionExtension` shape
 * (`getFileName()`, `getFunctions()`, `getClasses()`) and hands out the same
 * engine-struct wrappers the rest of z-engine uses (ReflectionFunction,
 * ReflectionClass, HashTable, ReflectionValue), so a mutation made through them
 * lands in the image and is written back by {@see BinaryCacheFile::save()}.
 *
 * No public method returns a CData (AGENTS.md): callers see framework wrapper
 * objects only. Constructed exclusively by BinaryCacheFile::getReflection().
 *
 * @internal instances come from BinaryCacheFile::getReflection()
 */
final class ReflectionOpcacheFile
{
    /** Set once a mutation outgrew the original buffer; routes save() to ScriptSerializer */
    private bool $graphGrown = false;

    /** @var list<self> donor images whose units this image now references */
    private array $donors = [];

    /**
     * @param \FFI\CData  $script     Relocated zend_persistent_script inside the image buffer
     * @param object|null $imageOwner Owner of the relocated buffer (the PayloadRelocator):
     *                                retained so that holding this view alone provably keeps
     *                                the buffer - which every wrapper this view hands out
     *                                points into - alive (see CacheImageSync, whose swapped-in
     *                                bodies keep executing out of that buffer)
     */
    public function __construct(
        private readonly object $script,
        // @phpstan-ignore property.onlyWritten (lifetime pin: held, never read)
        private readonly ?object $imageOwner = null,
    ) {}

    /**
     * The relocated zend_persistent_script this handle wraps
     *
     * @internal core-layer escape hatch for BinaryCacheFile/ScriptSerializer
     * @return \FFI\CData
     */
    public function getRawScript(): object
    {
        return $this->script;
    }

    /** Whether a mutation grew the graph beyond the original buffer */
    public function isGraphGrown(): bool
    {
        return $this->graphGrown;
    }

    /**
     * The donor images this image references after grafting; their buffers must
     * stay materialized until save() re-emits the graph into one fresh region
     *
     * @return list<self>
     */
    public function donorImages(): array
    {
        return $this->donors;
    }

    /**
     * The cached script's source path (parity with ReflectionClass::getFileName())
     */
    public function getFileName(): string
    {
        return StringEntry::fromCData($this->script->script->filename)->getStringValue();
    }

    /**
     * The main (file-level) op_array as a ReflectionFunction
     */
    public function getScriptFunction(): ReflectionFunction
    {
        $function = Core::cast('zend_function *', FFI::addr($this->script->script->main_op_array));

        return ReflectionFunction::fromCData($function);
    }

    /**
     * Borrowed view over the compiled function table
     */
    public function functionTable(): HashTable
    {
        return HashTable::fromCData(FFI::addr($this->script->script->function_table));
    }

    /**
     * Borrowed view over the compiled class table
     */
    public function classTable(): HashTable
    {
        return HashTable::fromCData(FFI::addr($this->script->script->class_table));
    }

    /**
     * Grafts a function from another cache image into this script (issue #117).
     *
     * The donor must come from a cache binary compiled by a real opcache child,
     * so its op_array is already in file form (handler indexes, literal-index
     * operands) - the graph serializer copies such units verbatim. The donor
     * image is referenced, not copied, until {@see BinaryCacheFile::save()}
     * re-emits the whole graph through {@see ScriptSerializer}.
     */
    public function addFunctionFrom(self $donor, string $functionName): void
    {
        $key   = strtolower($functionName);
        $entry = self::findKeyedEntry($donor->script->script->function_table, $key);
        if ($entry === null) {
            throw OpCacheException::graftEntryNotFound('function', $functionName);
        }
        [$keyAddress, $functionAddress] = $entry;
        $this->insertPtrEntry($this->script->script->function_table, $keyAddress, $functionAddress);
        $this->donors[]   = $donor;
        $this->graphGrown = true;
    }

    /**
     * Grafts a method from a donor image's class into a class of this script.
     *
     * The donor method's scope is re-pointed at the target class (the donor
     * image is mutated - it is tied to this image from here on), exactly what
     * zend_persist expects of a method hanging off that class's function table.
     */
    public function addMethodFrom(self $donor, string $donorClassName, string $methodName, string $targetClassName): void
    {
        $donorClass = $donor->findClassByName($donorClassName);
        if ($donorClass === null) {
            throw OpCacheException::graftEntryNotFound('class', $donorClassName);
        }
        $targetClass = $this->findClassByName($targetClassName);
        if ($targetClass === null) {
            throw OpCacheException::graftEntryNotFound('class', $targetClassName);
        }
        $entry = self::findKeyedEntry($donorClass->function_table, strtolower($methodName));
        if ($entry === null) {
            throw OpCacheException::graftEntryNotFound('method', "{$donorClassName}::{$methodName}");
        }
        [$keyAddress, $methodAddress] = $entry;

        // Re-point the method's scope at the adopting class
        $method                                                 = Core::pointerAtAddress('zend_op_array *', $methodAddress);
        Core::cast('uintptr_t *', FFI::addr($method->scope))[0] = Core::addressOf($targetClass);

        $this->insertPtrEntry($targetClass->function_table, $keyAddress, $methodAddress);
        $this->donors[]   = $donor;
        $this->graphGrown = true;
    }

    /**
     * Every user function compiled into the script, keyed by lowercase name
     * (parity with ReflectionExtension::getFunctions())
     *
     * @return array<string, ReflectionFunction>
     */
    public function getFunctions(): array
    {
        $functions = [];
        // The function-table bucket key is already the canonical lowercased
        // function name (parity with ReflectionExtension::getFunctions())
        foreach ($this->functionTable() as $name => $value) {
            $functions[(string) $name] = ReflectionFunction::fromCData($value->getRawFunction());
        }

        return $functions;
    }

    /**
     * Every class compiled into the script, keyed by lowercase name
     * (parity with ReflectionExtension::getClasses())
     *
     * @return array<string, ReflectionClass>
     */
    public function getClasses(): array
    {
        $classes = [];
        // Early-bound classes are stored under an opcache runtime-definition
        // key (a NUL-prefixed rtd key), not their plain name, so key the map
        // by the entry's own lowercased name (parity with ReflectionExtension)
        foreach ($this->classTable() as $value) {
            $class                                           = ReflectionClass::fromCData($value->getRawClass());
            $classes[strtolower((string) $class->getName())] = $class;
        }

        return $classes;
    }

    // --- graft plumbing (issue #117) ----------------------------------------

    /**
     * Finds a class entry by its own name, case-insensitively; class-table
     * bucket keys can be opcache rtd keys, so match on ce->name instead.
     *
     * @return \FFI\CData|null a zend_class_entry* into the image
     */
    private function findClassByName(string $className): ?object
    {
        $ht          = $this->script->script->class_table;
        $bucketSize  = Core::sizeOfType(Bucket::class);
        $dataAddress = Core::addressOf($ht->arData);
        for ($i = 0; $i < $ht->nNumUsed; $i++) {
            $bucket = Core::pointerAtAddress('Bucket *', $dataAddress + $i * $bucketSize);
            if ($bucket->val->u1->v->type === 0) {
                continue;
            }
            $classEntry = Core::pointerAtAddress(
                'zend_class_entry *',
                (int) Core::cast('uintptr_t *', FFI::addr($bucket->val->value))[0],
            );
            $name = StringEntry::fromCData($classEntry->name)->getStringValue();
            if (strcasecmp($name, $className) === 0) {
                return $classEntry;
            }
        }

        return null;
    }

    /**
     * Finds a bucket by exact key in a keyed image table.
     *
     * @param \FFI\CData $ht HashTable view
     * @return array{int, int}|null [key zend_string address, value pointer address]
     */
    private static function findKeyedEntry(object $ht, string $key): ?array
    {
        if (($ht->u->flags & Core::engineConstant('HASH_FLAG_UNINITIALIZED')) !== 0) {
            return null;
        }
        $bucketSize  = Core::sizeOfType(Bucket::class);
        $dataAddress = Core::addressOf($ht->arData);
        for ($i = 0; $i < $ht->nNumUsed; $i++) {
            $bucket = Core::pointerAtAddress('Bucket *', $dataAddress + $i * $bucketSize);
            if ($bucket->val->u1->v->type === 0 || $bucket->key === null) {
                continue;
            }
            if (StringEntry::fromCData($bucket->key)->getStringValue() === $key) {
                return [
                    Core::addressOf($bucket->key),
                    (int) Core::cast('uintptr_t *', FFI::addr($bucket->val->value))[0],
                ];
            }
        }

        return null;
    }

    /**
     * Inserts an IS_PTR entry into an image hashtable, regrowing its data block
     * outside the buffer (issue #117). Image tables were laid out by
     * zend_hash_persist - their data is NOT an emalloc'd block, so the engine's
     * zend_hash_add must never touch them; this reimplements the insert the way
     * the persisted format expects it (hash slots ahead of arData, bucket-index
     * chains via Z_NEXT, HT_SIZE_TO_MASK = -(2 * nTableSize)).
     *
     * @param \FFI\CData $ht HashTable view (embedded in the image)
     */
    private function insertPtrEntry(object $ht, int $keyAddress, int $valueAddress): void
    {
        $flags = $ht->u->flags;
        if (($flags & Core::engineConstant('HASH_FLAG_PACKED')) !== 0) {
            throw OpCacheException::unsupportedPayload('grafting into a packed hashtable');
        }
        $key  = Core::pointerAtAddress('zend_string *', $keyAddress);
        $hash = $key->h;
        if ($hash === 0) {
            throw OpCacheException::unsupportedPayload('graft key string carries no precomputed hash');
        }

        $bucketSize    = Core::sizeOfType(Bucket::class);
        $uninitialized = ($flags & Core::engineConstant('HASH_FLAG_UNINITIALIZED')) !== 0;
        $used          = $uninitialized ? 0 : $ht->nNumUsed;
        $tableSize     = $uninitialized ? 8 : $ht->nTableSize;
        $oldData       = $uninitialized ? 0 : Core::addressOf($ht->arData);

        if (!$uninitialized && self::findKeyedEntry($ht, StringEntry::fromCData($key)->getStringValue()) !== null) {
            throw OpCacheException::duplicateHashTableKey(StringEntry::fromCData($key)->getStringValue());
        }

        $newUsed = $used + 1;
        while ($newUsed > $tableSize) {
            $tableSize <<= 1;
        }
        // HT_SIZE_TO_MASK(nTableSize) = (uint32)(-(nTableSize + nTableSize))
        $newMask   = (0x100000000 - 2 * $tableSize) & 0xFFFFFFFF;
        $hashBytes = 2                       * $tableSize * 4;
        $capacity  = $hashBytes + $tableSize * $bucketSize;
        $block     = Core::new("char[{$capacity}]", false);
        $blockBase = Core::addressOf(Core::addr($block));
        $newData   = $blockBase + $hashBytes;

        if ($used > 0) {
            FFI::memcpy(
                Core::cast('char *', Core::pointerAtAddress('void *', $newData)),
                Core::cast('char *', Core::pointerAtAddress('void *', $oldData)),
                $used * $bucketSize,
            );
        }

        // The appended bucket: an IS_PTR zval, hash and key
        $bucket                                                      = Core::pointerAtAddress('Bucket *', $newData + $used * $bucketSize);
        $bucket->val->u1->type_info                                  = Core::engineConstant('IS_PTR');
        Core::cast('uintptr_t *', FFI::addr($bucket->val->value))[0] = $valueAddress;
        $bucket->h                                                   = $hash;
        $bucket->key                                                 = $key;

        // HT_HASH_RESET + full rehash (bucket-index chains, like zend_hash_persist)
        for ($i = 0; $i < 2 * $tableSize; $i++) {
            Core::cast('uint32_t *', Core::pointerAtAddress('void *', $blockBase + $i * 4))[0] = 0xFFFFFFFF; // HT_INVALID_IDX
        }
        for ($idx = 0; $idx < $newUsed; $idx++) {
            $entry = Core::pointerAtAddress('Bucket *', $newData + $idx * $bucketSize);
            if ($entry->val->u1->v->type === 0) {
                continue;
            }
            $nIndex                                                                  = ($entry->h | $newMask) & 0xFFFFFFFF;
            $slot                                                                    = $nIndex - 0x100000000; // (int32_t)nIndex, always negative
            $slotAddr                                                                = $newData + $slot * 4;
            $entry->val->u2->next                                                    = (int) Core::cast('uint32_t *', Core::pointerAtAddress('void *', $slotAddr))[0];
            Core::cast('uint32_t *', Core::pointerAtAddress('void *', $slotAddr))[0] = $idx;
        }

        $ht->arData           = Core::pointerAtAddress('Bucket *', $newData);
        $ht->nNumUsed         = $newUsed;
        $ht->nNumOfElements   = ($uninitialized ? 0 : $ht->nNumOfElements) + 1;
        $ht->nTableSize       = $tableSize;
        $ht->nTableMask       = $newMask;
        $ht->nInternalPointer = 0;
        if ($uninitialized) {
            $ht->u->flags = $flags & ~Core::engineConstant('HASH_FLAG_UNINITIALIZED');
        }
    }
}
