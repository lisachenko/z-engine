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
use ZEngine\Generated\zend_arg_info;
use ZEngine\Generated\zend_ast;
use ZEngine\Generated\zend_ast_list;
use ZEngine\Generated\zend_ast_ref;
use ZEngine\Generated\zend_attribute_arg;
use ZEngine\Generated\zend_class_name;
use ZEngine\Generated\zend_early_binding;
use ZEngine\Generated\zend_persistent_script;
use ZEngine\Generated\zend_string;
use ZEngine\Generated\zend_type;
use ZEngine\Generated\zend_type_list;
use ZEngine\Generated\zval;

/**
 * Turns the position-independent file-cache payload into a live in-memory
 * image and back, a faithful port of ext/opcache/zend_file_cache.c
 * (unserialize = {@see relocate}, serialize = {@see derelocate}) for 64-bit
 * POSIX builds, NTS and ZTS alike: zend_file_cache.c has no thread-safety
 * conditionals, and every struct the walker dereferences is layout-identical
 * across the two modes (only EG/CG/module_entry differ on ZTS, none of which
 * appear in a payload) - verified against the generated layouts.json of both
 * targets (issue #118).
 *
 * In the file every interior pointer is stored as a byte offset from the
 * buffer start (SERIALIZE_PTR) and every interned string as a tagged offset
 * into the appended string section (IS_SERIALIZED_INTERNED). relocate()
 * rewrites those to real process addresses so the existing engine-struct
 * wrappers (HashTable, ReflectionClass/Function, ReflectionValue, ...) can
 * walk the image natively; derelocate() rebuilds the exact serialized bytes,
 * re-emitting the interned-string section so size-changing string edits made
 * through the wrappers are written correctly.
 *
 * The image is never EXECUTED in this process - opcache re-derives the
 * execution-only state (run-time caches, opcode handlers, class-name map-ptr
 * slots, object-handler tables) when it loads the written binary. So this
 * port deliberately does not touch, and preserves byte-for-byte, the fields
 * that only matter for execution: opline operands and handlers, ZEND_MAP_PTR
 * slots and the string GC flag bits. Everything structural is converted.
 *
 * @internal core-layer machinery; takes and returns CData like FunctionBodySwap
 */
final class PayloadRelocator
{
    private const int IS_STRING       = 6;
    private const int IS_ARRAY        = 7;
    private const int IS_INDIRECT     = 12;
    private const int IS_CONSTANT_AST = 11;

    private const int HASH_FLAG_UNINITIALIZED = 8;
    private const int HASH_FLAG_PACKED        = 4;

    private const int ZEND_ACC_LINKED          = 0x8;
    private const int ZEND_ACC_HAS_RETURN_TYPE = 0x2000;
    private const int ZEND_ACC_VARIADIC        = 0x4000;

    private const int ZEND_AST_ZVAL           = 64;
    private const int ZEND_AST_CONSTANT       = 65;
    private const int ZEND_AST_IS_LIST_SHIFT  = 7;
    private const int ZEND_AST_CHILDREN_SHIFT = 8;

    /** zend_type bit layout (zend_types.h) - list/name discriminators */
    private const int TYPE_LIST_BIT = 4194304;  // _ZEND_TYPE_LIST_BIT
    private const int TYPE_NAME_BIT = 16777216; // _ZEND_TYPE_NAME_BIT

    /** ZEND_PROPERTY_HOOK_COUNT (zend_property_hooks.h) - get + set slots */
    private const int PROPERTY_HOOK_COUNT = 2;

    private readonly int $base;
    private readonly int $size;
    /**
     * Upper bound for tagged interned-string offsets. Starts at the header's
     * str_size and is re-pinned to the rebuilt string-section length whenever
     * serialize() re-emits it, so the relocate() inside derelocate() validates
     * against the section it just produced, not the stale original size.
     */
    private int $strSize;
    private readonly int $strSectionBase;

    /** Interned-string re-emission state (write path) */
    private string $strSection = '';
    /** @var array<int, int> address of an interned string => its tagged offset */
    private array $internedXlat = [];
    /** @var array<int, true> opcodes address => already-serialized guard for shared method bodies */
    private array $sharedOpcodes = [];

    private readonly int $zendStringHeaderSize;

    /**
     * Whether the relocator can handle payloads of the running build at all
     *
     * The exact predicate the constructor enforces, exposed so callers (and the tests
     * covering them) can skip cleanly instead of provoking the throw. Windows opcache
     * support is an intentional non-goal (issue #119 was rescoped to macOS/arm64).
     */
    public static function isSupported(): bool
    {
        return PHP_INT_SIZE === 8 && \DIRECTORY_SEPARATOR === '/';
    }

    /**
     * @param CData         $buffer   char[mem_size + str_size] holding the raw payload
     * @param CacheMetaInfo $metaInfo Parsed header describing the buffer regions
     */
    public function __construct(private readonly object $buffer, private readonly CacheMetaInfo $metaInfo)
    {
        if (PHP_INT_SIZE !== 8 || \DIRECTORY_SEPARATOR !== '/') {
            throw OpCacheException::unsupportedPayload('the relocator supports 64-bit non-Windows builds only');
        }
        $this->base           = Core::addressOf(Core::addr($buffer));
        $this->size           = $metaInfo->memSize();
        $this->strSize        = $metaInfo->strSize();
        $this->strSectionBase = $this->base + $this->size;
        // _ZSTR_HEADER_SIZE = XtOffsetOf(zend_string, val): the flexible val[1]
        // member starts at the last 8-byte slot of the (padded) struct, so the
        // header is sizeof - 8, NOT sizeof - 1 (which over-copied 7 bytes per
        // interned emission and diverged from _ZSTR_STRUCT_SIZE)
        $this->zendStringHeaderSize = Core::sizeOfType(zend_string::class) - PHP_INT_SIZE;
    }

    /**
     * Rewrites the buffer in place, converting every stored offset to a real
     * address, and returns a typed pointer to the embedded zend_persistent_script.
     *
     * @return \FFI\CData
     */
    public function relocate(): object
    {
        $this->sharedOpcodes = [];
        // The script struct itself must fit inside the mem region before we
        // dereference a single field of it (issue #123)
        $this->requireSpan(
            $this->metaInfo->scriptOffset(),
            Core::sizeOfType(zend_persistent_script::class),
            'zend_persistent_script at scriptOffset',
        );
        $script = Core::pointerAtAddress('zend_persistent_script *', $this->base + $this->metaInfo->scriptOffset());

        $this->unStr($script->script, 'filename');
        $this->unserializeHash($script->script->class_table, $this->unserializeClass(...));
        $this->unserializeHash($script->script->function_table, $this->unserializeFunc(...));
        $this->unserializeOpArray($script->script->main_op_array);
        $this->unserializeWarnings($script);
        $this->unserializeEarlyBindings($script);

        return $script;
    }

    /**
     * Produces the serialized payload bytes (mem region followed by the
     * rebuilt interned-string section) from the relocated image.
     *
     * The buffer is serialized destructively in place and then relocated
     * again, so a materialized view stays valid afterwards (relocate() and
     * serialize() are exact inverses).
     */
    public function derelocate(): string
    {
        $bytes = $this->serialize();
        $this->relocate();

        return $bytes;
    }

    /**
     * Walks the (real-pointer) image in place, converting every pointer back to
     * an offset and re-emitting interned strings; returns mem region + strings.
     */
    private function serialize(): string
    {
        $this->strSection    = '';
        $this->internedXlat  = [];
        $this->sharedOpcodes = [];
        $script              = Core::pointerAtAddress('zend_persistent_script *', $this->base + $this->metaInfo->scriptOffset());

        $this->serStr($script->script, 'filename');
        $this->serializeHash($script->script->class_table, $this->serializeClass(...));
        $this->serializeHash($script->script->function_table, $this->serializeFunc(...));
        $this->serializeOpArray($script->script->main_op_array);
        $this->serializeWarnings($script);
        $this->serializeEarlyBindings($script);

        $memRegion = FFI::string($this->buffer, $this->size);
        // Re-pin the tagged-offset bound to the section just emitted, so the
        // relocate() in derelocate() validates against it (issue #123)
        $this->strSize = strlen($this->strSection);

        return $memRegion . $this->strSection;
    }

    // --- pointer/offset primitives (SERIALIZE_PTR / UNSERIALIZE_PTR) --------

    /**
     * Reads a pointer field's stored value through a raw integer view of its
     * storage, so it works for every pointee type including void* (which
     * Core::addressOf cannot cast). 0 when the C NULL surfaces as PHP null.
     *
     * @param \FFI\CData $owner
     */
    private function ptrValue(object $owner, string $field): int
    {
        if ($owner->$field === null) {
            return 0;
        }

        return (int) Core::cast('uintptr_t *', FFI::addr($owner->$field))[0];
    }
    /**
     * @param \FFI\CData $owner
     */

    private function writePtrField(object $owner, string $field, int $address): void
    {
        // Only ever called for a currently non-null field, so FFI::addr is safe
        $slot    = Core::cast('uintptr_t *', FFI::addr($owner->$field));
        $slot[0] = $address;
    }

    private function isSerialized(int $pointer): bool
    {
        // Upper bound inclusive: a return-type-only &arg_info[1] may point at the region end
        return $pointer <= $this->size;
    }

    private function isUnserialized(int $pointer): bool
    {
        return $pointer >= $this->base && $pointer <= $this->base + $this->size;
    }

    // --- bounds validation (issue #123) -------------------------------------
    // Every stored offset in the file is attacker-controllable in an untrusted
    // binary (system_id is a build fingerprint, adler32 is forgeable), so each
    // one is range-checked before it becomes a real address the engine walks.
    // The checks live in the UNSERIALIZE (relocate) primitives and the
    // count-driven relocate loops - the derelocate/serialize path and the graph
    // serializer both operate on an already-relocated, in-process image and
    // inherit that image's validation.

    /**
     * Validates a stored mem-region offset lies in [0, size]. The upper bound is
     * inclusive because a return-type-only &arg_info[1] legitimately points at
     * the region end. Returns the offset for fluent use.
     */
    private function requireOffset(int $stored, string $what): int
    {
        if ($stored < 0 || $stored > $this->size) {
            throw OpCacheException::malformedPayload(
                sprintf('%s: stored offset %d is outside [0, %d]', $what, $stored, $this->size),
            );
        }

        return $stored;
    }

    /**
     * Validates a stored zend_string reference: a tagged interned reference must
     * land in the appended string section [0, strSize), a plain one in the mem
     * region [0, size].
     */
    private function requireStringOffset(int $stored, string $what): void
    {
        if (($stored & 1) !== 0) {
            $offset = $stored & ~1;
            if ($offset < 0 || $offset >= $this->strSize) {
                throw OpCacheException::malformedPayload(
                    sprintf('%s: interned-string offset %d is outside [0, %d)', $what, $offset, $this->strSize),
                );
            }

            return;
        }
        $this->requireOffset($stored, $what);
    }

    /**
     * Validates that [resolvedAddress, resolvedAddress + count * elementSize)
     * lies fully within the mem region, before a loop dereferences the span.
     * A negative or overflowing count is rejected too.
     */
    private function requireSpan(int $offsetOrAddress, int $bytes, string $what): void
    {
        // Accept either a stored offset or a resolved (base+offset) address
        $offset = $offsetOrAddress >= $this->base ? $offsetOrAddress - $this->base : $offsetOrAddress;
        if ($bytes < 0 || $offset < 0 || $offset > $this->size || $offset + $bytes > $this->size) {
            throw OpCacheException::malformedPayload(
                sprintf('%s: span [%d, %d) escapes the %d-byte mem region', $what, $offset, $offset + $bytes, $this->size),
            );
        }
    }

    /** Validates a count field before it drives an element-span walk */
    private function requireCount(int $count, string $what): int
    {
        if ($count < 0 || $count > $this->size) {
            throw OpCacheException::malformedPayload(
                sprintf('%s: implausible count %d for a %d-byte region', $what, $count, $this->size),
            );
        }

        return $count;
    }

    /** UNSERIALIZE_PTR on a struct field, returning the resolved address (0 if null) */
    /**
     * @param \FFI\CData $owner
     */
    private function unPtr(object $owner, string $field): int
    {
        $stored = $this->ptrValue($owner, $field);
        if ($stored === 0) {
            return 0;
        }
        $this->requireOffset($stored, "pointer field {$field}");
        $address = $this->base + $stored;
        $this->writePtrField($owner, $field, $address);

        return $address;
    }

    /** SERIALIZE_PTR on a struct field, returning the pre-serialization address (0 if null) */
    /**
     * @param \FFI\CData $owner
     */
    private function serPtr(object $owner, string $field): int
    {
        $address = $this->ptrValue($owner, $field);
        if ($address === 0) {
            return 0;
        }
        $this->writePtrField($owner, $field, $address - $this->base);

        return $address;
    }

    /** UNSERIALIZE_PTR on a raw pointer slot, returning the resolved address (0 for a NULL slot) */
    private function unPtrAt(int $slotAddress): int
    {
        $slot   = Core::cast('uintptr_t *', Core::pointerAtAddress('void *', $slotAddress));
        $stored = (int) $slot[0];
        if ($stored === 0) {
            return 0;
        }
        $this->requireOffset($stored, 'raw pointer slot');
        $slot[0] = $this->base + $stored;

        return $this->base + $stored;
    }

    /** SERIALIZE_PTR on a raw pointer slot, returning the pre-serialization address (0 for a NULL slot) */
    private function serPtrAt(int $slotAddress): int
    {
        $slot    = Core::cast('uintptr_t *', Core::pointerAtAddress('void *', $slotAddress));
        $address = (int) $slot[0];
        if ($address === 0) {
            return 0;
        }
        $slot[0] = $address - $this->base;

        return $address;
    }

    // --- interned-string primitives (UNSERIALIZE_STR / SERIALIZE_STR) ------
    /**
     * @param \FFI\CData $owner
     */

    private function unStr(object $owner, string $field): void
    {
        $stored = $this->ptrValue($owner, $field);
        if ($stored === 0) {
            return;
        }
        $this->requireStringOffset($stored, "string field {$field}");
        if (($stored & 1) !== 0) {
            // Tagged interned reference into the string section
            $address = $this->strSectionBase + ($stored & ~1);
        } else {
            $address = $this->base + $stored;
        }
        // GC flag normalization is deliberately skipped (see class docblock)
        $this->writePtrField($owner, $field, $address);
    }
    /**
     * @param \FFI\CData $owner
     */

    private function serStr(object $owner, string $field): void
    {
        $address = $this->ptrValue($owner, $field);
        if ($address === 0) {
            return;
        }
        if ($address >= $this->base && $address < $this->base + $this->size) {
            // In-mem (non-interned) string: plain offset
            $this->writePtrField($owner, $field, $address - $this->base);

            return;
        }
        $this->writePtrField($owner, $field, $this->emitInterned($address));
    }

    /** UNSERIALIZE_STR on a raw zend_string* slot (no owning struct field) */
    private function unStrAt(int $slotAddress): void
    {
        $slot   = Core::cast('uintptr_t *', Core::pointerAtAddress('void *', $slotAddress));
        $stored = (int) $slot[0];
        if ($stored === 0) {
            return;
        }
        $this->requireStringOffset($stored, 'raw string slot');
        if (($stored & 1) !== 0) {
            $slot[0] = $this->strSectionBase + ($stored & ~1);
        } else {
            $slot[0] = $this->base + $stored;
        }
    }

    /** SERIALIZE_STR on a raw zend_string* slot (no owning struct field) */
    private function serStrAt(int $slotAddress): void
    {
        $slot    = Core::cast('uintptr_t *', Core::pointerAtAddress('void *', $slotAddress));
        $address = (int) $slot[0];
        if ($address === 0) {
            return;
        }
        if ($address >= $this->base && $address < $this->base + $this->size) {
            $slot[0] = $address - $this->base;
        } else {
            $slot[0] = $this->emitInterned($address);
        }
    }

    /**
     * Copies an interned string into the rebuilt string section (deduplicated)
     * and returns its tagged offset - the port of zend_file_cache_serialize_interned.
     */
    private function emitInterned(int $address): int
    {
        if (isset($this->internedXlat[$address])) {
            return $this->internedXlat[$address];
        }
        $stringPointer = Core::pointerAtAddress('zend_string *', $address);
        $length        = $stringPointer->len;
        $structSize    = Core::getAlignedSize($this->zendStringHeaderSize + $length + 1);

        $tagged                       = strlen($this->strSection) | 1;
        $this->internedXlat[$address] = $tagged;
        $this->strSection .= FFI::string(Core::cast('char *', $stringPointer), $structSize);

        return $tagged;
    }

    // --- hashes (zend_file_cache_(un)serialize_hash) -----------------------
    /**
     * @param \FFI\CData $ht
     */

    private function unserializeHash(object $ht, callable $each): void
    {
        if (($ht->u->flags & self::HASH_FLAG_UNINITIALIZED) !== 0) {
            return;
        }
        if ($this->isUnserialized($this->ptrValue($ht, 'arData'))) {
            return;
        }
        $dataAddress = $this->unPtr($ht, 'arData');
        $used        = $this->requireCount((int) $ht->nNumUsed, 'hashtable nNumUsed');
        if (($ht->u->flags & self::HASH_FLAG_PACKED) !== 0) {
            $zvalSize = Core::sizeOfType(zval::class);
            $this->requireSpan($dataAddress, $used * $zvalSize, 'packed hashtable data');
            for ($i = 0; $i < $used; $i++) {
                $zval = Core::pointerAtAddress('zval *', $dataAddress + $i * $zvalSize);
                if ($zval->u1->v->type !== 0) {
                    $each($zval);
                }
            }

            return;
        }
        $bucketSize = Core::sizeOfType(Bucket::class);
        $this->requireSpan($dataAddress, $used * $bucketSize, 'hashtable bucket data');
        for ($i = 0; $i < $used; $i++) {
            $bucket = Core::pointerAtAddress('Bucket *', $dataAddress + $i * $bucketSize);
            if ($bucket->val->u1->v->type !== 0) {
                $this->unStr($bucket, 'key');
                $each($bucket->val);
            }
        }
    }
    /**
     * @param \FFI\CData $ht
     */

    private function serializeHash(object $ht, callable $each): void
    {
        if (($ht->u->flags & self::HASH_FLAG_UNINITIALIZED) !== 0) {
            $this->writePtrField($ht, 'arData', 0);

            return;
        }
        if ($this->isSerialized($this->ptrValue($ht, 'arData'))) {
            return;
        }
        $dataAddress = $this->serPtr($ht, 'arData');
        $used        = $ht->nNumUsed;
        if (($ht->u->flags & self::HASH_FLAG_PACKED) !== 0) {
            $zvalSize = Core::sizeOfType(zval::class);
            for ($i = 0; $i < $used; $i++) {
                $zval = Core::pointerAtAddress('zval *', $dataAddress + $i * $zvalSize);
                if ($zval->u1->v->type !== 0) {
                    $each($zval);
                }
            }

            return;
        }
        $bucketSize = Core::sizeOfType(Bucket::class);
        for ($i = 0; $i < $used; $i++) {
            $bucket = Core::pointerAtAddress('Bucket *', $dataAddress + $i * $bucketSize);
            if ($bucket->val->u1->v->type !== 0) {
                $this->serStr($bucket, 'key');
                $each($bucket->val);
            }
        }
    }

    // --- zvals -------------------------------------------------------------
    /**
     * @param \FFI\CData $zval
     */

    private function unserializeZval(object $zval): void
    {
        switch ($zval->u1->v->type) {
            case self::IS_STRING:
                $stored = $this->ptrValue($zval->value, 'str');
                if ($this->isSerialized($stored) || ($stored & 1) !== 0) {
                    $this->unStr($zval->value, 'str');
                }
                break;
            case self::IS_ARRAY:
                if (!$this->isUnserialized($this->ptrValue($zval->value, 'arr'))) {
                    $arrAddress = $this->unPtr($zval->value, 'arr');
                    $this->unserializeHash(
                        Core::pointerAtAddress('zend_array *', $arrAddress),
                        $this->unserializeZval(...),
                    );
                }
                break;
            case self::IS_CONSTANT_AST:
                if (!$this->isUnserialized($this->ptrValue($zval->value, 'ast'))) {
                    $astRef = $this->unPtr($zval->value, 'ast');
                    $this->unserializeAst($astRef + Core::sizeOfType(zend_ast_ref::class));
                }
                break;
            case self::IS_INDIRECT:
                $this->unPtr($zval->value, 'zv');
                break;
        }
    }
    /**
     * @param \FFI\CData $zval
     */

    private function serializeZval(object $zval): void
    {
        switch ($zval->u1->v->type) {
            case self::IS_STRING:
                if (!$this->isSerialized($this->ptrValue($zval->value, 'str'))) {
                    $this->serStr($zval->value, 'str');
                }
                break;
            case self::IS_ARRAY:
                if (!$this->isSerialized($this->ptrValue($zval->value, 'arr'))) {
                    $arrAddress = $this->serPtr($zval->value, 'arr');
                    $this->serializeHash(
                        Core::pointerAtAddress('zend_array *', $arrAddress),
                        $this->serializeZval(...),
                    );
                }
                break;
            case self::IS_CONSTANT_AST:
                if (!$this->isSerialized($this->ptrValue($zval->value, 'ast'))) {
                    $astRef = $this->serPtr($zval->value, 'ast');
                    $this->serializeAst($astRef + Core::sizeOfType(zend_ast_ref::class));
                }
                break;
            case self::IS_INDIRECT:
                $this->serPtr($zval->value, 'zv');
                break;
        }
    }

    // --- AST (zend_ast_ref wraps a zend_ast; walked via zend_ast) ----------

    /** @param int $astAddress address of the zend_ast (already resolved) */
    private function unserializeAst(int $astAddress): void
    {
        // The node header (kind + attr) must fit before it is read
        $this->requireSpan($astAddress, Core::sizeOfType(zend_ast::class), 'zend_ast node');
        $ast  = Core::pointerAtAddress('zend_ast *', $astAddress);
        $kind = $ast->kind;
        if ($kind === self::ZEND_AST_ZVAL || $kind === self::ZEND_AST_CONSTANT) {
            $this->unserializeZval(Core::pointerAtAddress('zend_ast_zval *', $astAddress)->val);

            return;
        }
        if (($kind >> self::ZEND_AST_IS_LIST_SHIFT & 1) !== 0) {
            $list      = Core::pointerAtAddress('zend_ast_list *', $astAddress);
            $childBase = $astAddress + Core::sizeOfType(zend_ast_list::class) - PHP_INT_SIZE;
            $count     = $this->requireCount((int) $list->children, 'ast list children');
        } else {
            $childBase = $astAddress + Core::sizeOfType(zend_ast::class) - PHP_INT_SIZE;
            $count     = $kind >> self::ZEND_AST_CHILDREN_SHIFT;
        }
        $this->requireSpan($childBase, $count * PHP_INT_SIZE, 'ast children slots');
        for ($i = 0; $i < $count; $i++) {
            $slot  = Core::cast('uintptr_t *', Core::pointerAtAddress('void *', $childBase + $i * PHP_INT_SIZE));
            $child = (int) $slot[0];
            if ($child !== 0 && !$this->isUnserialized($child)) {
                $this->requireOffset($child, 'ast child');
                $slot[0] = $this->base + $child;
                $this->unserializeAst($this->base + $child);
            }
        }
    }

    private function serializeAst(int $astAddress): void
    {
        $ast  = Core::pointerAtAddress('zend_ast *', $astAddress);
        $kind = $ast->kind;
        if ($kind === self::ZEND_AST_ZVAL || $kind === self::ZEND_AST_CONSTANT) {
            $this->serializeZval(Core::pointerAtAddress('zend_ast_zval *', $astAddress)->val);

            return;
        }
        if (($kind >> self::ZEND_AST_IS_LIST_SHIFT & 1) !== 0) {
            $list      = Core::pointerAtAddress('zend_ast_list *', $astAddress);
            $childBase = $astAddress + Core::sizeOfType(zend_ast_list::class) - PHP_INT_SIZE;
            $count     = $list->children;
        } else {
            $childBase = $astAddress + Core::sizeOfType(zend_ast::class) - PHP_INT_SIZE;
            $count     = $kind >> self::ZEND_AST_CHILDREN_SHIFT;
        }
        for ($i = 0; $i < $count; $i++) {
            $slot  = Core::cast('uintptr_t *', Core::pointerAtAddress('void *', $childBase + $i * PHP_INT_SIZE));
            $child = (int) $slot[0];
            if ($child !== 0 && !$this->isSerialized($child)) {
                $slot[0] = $child - $this->base;
                $this->serializeAst($child);
            }
        }
    }

    // --- attributes --------------------------------------------------------
    /**
     * @param \FFI\CData $owner
     */

    private function unserializeAttributes(object $owner, string $field): void
    {
        $stored = $this->ptrValue($owner, $field);
        if ($stored === 0 || $this->isUnserialized($stored)) {
            return;
        }
        $htAddress = $this->unPtr($owner, $field);
        $this->unserializeHash(
            Core::pointerAtAddress('HashTable *', $htAddress),
            $this->unserializeAttribute(...),
        );
    }
    /**
     * @param \FFI\CData $owner
     */

    private function serializeAttributes(object $owner, string $field): void
    {
        $stored = $this->ptrValue($owner, $field);
        if ($stored === 0 || $this->isSerialized($stored)) {
            return;
        }
        $htAddress = $this->serPtr($owner, $field);
        $this->serializeHash(
            Core::pointerAtAddress('HashTable *', $htAddress),
            $this->serializeAttribute(...),
        );
    }
    /**
     * @param \FFI\CData $zval
     */

    private function unserializeAttribute(object $zval): void
    {
        $attr = Core::pointerAtAddress('zend_attribute *', $this->unPtr($zval->value, 'ptr'));
        $this->unStr($attr, 'name');
        $this->unStr($attr, 'lcname');
        $argSize = Core::sizeOfType(zend_attribute_arg::class);
        $argBase = Core::addressOf($attr->args);
        $argc    = $this->requireCount((int) $attr->argc, 'attribute argc');
        $this->requireSpan($argBase, $argc * $argSize, 'attribute args');
        for ($i = 0; $i < $argc; $i++) {
            $arg = Core::pointerAtAddress('zend_attribute_arg *', $argBase + $i * $argSize);
            $this->unStr($arg, 'name');
            $this->unserializeZval($arg->value);
        }
    }
    /**
     * @param \FFI\CData $zval
     */

    private function serializeAttribute(object $zval): void
    {
        $address = $this->serPtr($zval->value, 'ptr');
        $attr    = Core::pointerAtAddress('zend_attribute *', $address);
        $this->serStr($attr, 'name');
        $this->serStr($attr, 'lcname');
        $argSize = Core::sizeOfType(zend_attribute_arg::class);
        $argBase = Core::addressOf($attr->args);
        for ($i = 0; $i < $attr->argc; $i++) {
            $arg = Core::pointerAtAddress('zend_attribute_arg *', $argBase + $i * $argSize);
            $this->serStr($arg, 'name');
            $this->serializeZval($arg->value);
        }
    }

    // --- types (zend_type name/list) ---------------------------------------
    /**
     * @param \FFI\CData $owner
     */

    private function unserializeType(object $owner, string $field): void
    {
        $this->unserializeTypeStruct($owner->$field);
    }
    /**
     * @param \FFI\CData $owner
     */

    private function serializeType(object $owner, string $field): void
    {
        $this->serializeTypeStruct($owner->$field);
    }

    /**
     * One zend_type in place - the ZEND_TYPE_HAS_LIST branch of
     * zend_file_cache_unserialize_type relocates the zend_type_list pointer and
     * recurses into every entry, so DNF sub-lists like (A&B)|C unfold naturally.
     *
     * @param \FFI\CData $type a zend_type view (embedded field or list entry)
     */
    private function unserializeTypeStruct(object $type): void
    {
        $typeMask = $type->type_mask;
        if (($typeMask & self::TYPE_LIST_BIT) !== 0) {
            $listAddress = $this->unPtr($type, 'ptr');
            $this->requireSpan($listAddress, Core::sizeOfType(zend_type_list::class), 'zend_type_list header');
            $list     = Core::pointerAtAddress('zend_type_list *', $listAddress);
            $typeSize = Core::sizeOfType(zend_type::class);
            // ZEND_TYPE_LIST_FOREACH: entries start at list->types (the flexible member)
            $entryBase = $listAddress + Core::sizeOfType(zend_type_list::class) - $typeSize;
            $numTypes  = $this->requireCount((int) $list->num_types, 'type list num_types');
            $this->requireSpan($entryBase, $numTypes * $typeSize, 'type list entries');
            for ($i = 0; $i < $numTypes; $i++) {
                $this->unserializeTypeStruct(Core::pointerAtAddress('zend_type *', $entryBase + $i * $typeSize));
            }

            return;
        }
        if (($typeMask & self::TYPE_NAME_BIT) !== 0) {
            $this->unStr($type, 'ptr');
        }
    }

    /**
     * Mirror of {@see unserializeTypeStruct} - zend_file_cache_serialize_type
     * stores the list pointer as an offset but keeps walking the entries through
     * the still-real address (its SERIALIZE_PTR/UNSERIALIZE_PTR pair).
     *
     * @param \FFI\CData $type a zend_type view (embedded field or list entry)
     */
    private function serializeTypeStruct(object $type): void
    {
        $typeMask = $type->type_mask;
        if (($typeMask & self::TYPE_LIST_BIT) !== 0) {
            $listAddress = $this->serPtr($type, 'ptr');
            $list        = Core::pointerAtAddress('zend_type_list *', $listAddress);
            $typeSize    = Core::sizeOfType(zend_type::class);
            $entryBase   = $listAddress + Core::sizeOfType(zend_type_list::class) - $typeSize;
            for ($i = 0; $i < $list->num_types; $i++) {
                $this->serializeTypeStruct(Core::pointerAtAddress('zend_type *', $entryBase + $i * $typeSize));
            }

            return;
        }
        if (($typeMask & self::TYPE_NAME_BIT) !== 0) {
            $this->serStr($type, 'ptr');
        }
    }

    // --- op_array (the executable body) ------------------------------------
    /**
     * @param \FFI\CData $zval
     */

    private function unserializeFunc(object $zval): void
    {
        $func = Core::pointerAtAddress('zend_function *', $this->unPtr($zval->value, 'func'));
        $this->unserializeOpArray($func->op_array);
    }
    /**
     * @param \FFI\CData $zval
     */

    private function serializeFunc(object $zval): void
    {
        $func = Core::pointerAtAddress('zend_function *', $this->serPtr($zval->value, 'func'));
        $this->serializeOpArray($func->op_array);
    }
    /**
     * @param \FFI\CData $opArray
     */

    private function unserializeOpArray(object $opArray): void
    {
        // ZEND_MAP_PTR / run-time cache normalization is skipped (never executed here)
        if ($this->isUnserialized($this->ptrValue($opArray, 'opcodes'))) {
            return; // shared method body already relocated
        }
        if ($this->ptrValue($opArray, 'refcount') !== 0) {
            $this->writePtrField($opArray, 'refcount', 0);
            $this->unPtr($opArray, 'static_variables');
            $this->unPtr($opArray, 'literals');
            $this->unPtr($opArray, 'opcodes');
            $this->unPtr($opArray, 'arg_info');
            $this->unPtr($opArray, 'vars');
            $this->unStr($opArray, 'function_name');
            $this->unStr($opArray, 'filename');
            $this->unPtr($opArray, 'live_range');
            $this->unPtr($opArray, 'scope');
            $this->unStr($opArray, 'doc_comment');
            $this->unserializeAttributes($opArray, 'attributes');
            $this->unPtr($opArray, 'try_catch_array');
            $this->unPtr($opArray, 'prototype');
            $this->unPtr($opArray, 'prop_info');

            return;
        }

        if ($this->ptrValue($opArray, 'static_variables') !== 0) {
            $address = $this->unPtr($opArray, 'static_variables');
            $this->unserializeHash(Core::pointerAtAddress('zend_array *', $address), $this->unserializeZval(...));
        }
        if ($this->ptrValue($opArray, 'literals') !== 0) {
            $address  = $this->unPtr($opArray, 'literals');
            $zvalSize = Core::sizeOfType(zval::class);
            $count    = $this->requireCount((int) $opArray->last_literal, 'op_array last_literal');
            $this->requireSpan($address, $count * $zvalSize, 'op_array literals');
            for ($i = 0; $i < $count; $i++) {
                $this->unserializeZval(Core::pointerAtAddress('zval *', $address + $i * $zvalSize));
            }
        }
        // opcodes: only the array pointer is relocated. Per-opline operands are
        // preserved verbatim because every 64-bit build uses relative addressing:
        // ZEND_USE_ABS_CONST_ADDR / ZEND_USE_ABS_JMP_ADDR are 1 only when
        // SIZEOF_SIZE_T == 4 (zend_compile.h), so in these payloads IS_CONST
        // operands are literal-table indexes and jump operands are opline-relative
        // byte offsets - position-independent on linux and darwin alike. The
        // absolute-address per-opline walk of zend_file_cache.c is a 32-bit-only
        // shape, excluded with the 32-bit refusal (issue #119;
        // OpcodeAddressingModelTest is the tripwire should a build ever diverge).
        $this->unPtr($opArray, 'opcodes');
        $this->unPtr($opArray, 'scope');
        $this->unserializeArgInfo($opArray);
        $this->unserializeVars($opArray);
        if ($opArray->num_dynamic_func_defs !== 0) {
            // zend_op_array* array: relocate it, then recurse into each nested body
            $defsAddress = $this->unPtr($opArray, 'dynamic_func_defs');
            $count       = $this->requireCount((int) $opArray->num_dynamic_func_defs, 'num_dynamic_func_defs');
            $this->requireSpan($defsAddress, $count * PHP_INT_SIZE, 'dynamic_func_defs table');
            for ($i = 0; $i < $count; $i++) {
                $defAddress = $this->unPtrAt($defsAddress + $i * PHP_INT_SIZE);
                $this->unserializeOpArray(Core::pointerAtAddress('zend_op_array *', $defAddress));
            }
        }
        $this->unStr($opArray, 'function_name');
        $this->unStr($opArray, 'filename');
        $this->unPtr($opArray, 'live_range');
        $this->unStr($opArray, 'doc_comment');
        $this->unserializeAttributes($opArray, 'attributes');
        $this->unPtr($opArray, 'try_catch_array');
        $this->unPtr($opArray, 'prototype');
        $this->unPtr($opArray, 'prop_info');
    }
    /**
     * @param \FFI\CData $opArray
     */

    private function serializeOpArray(object $opArray): void
    {
        if ($this->isSerialized($this->ptrValue($opArray, 'opcodes'))) {
            return;
        }
        $opcodesAddress = $this->ptrValue($opArray, 'opcodes');
        if ($this->ptrValue($opArray, 'scope') !== 0) {
            if (isset($this->sharedOpcodes[$opcodesAddress])) {
                $this->writePtrField($opArray, 'refcount', $this->base); // sentinel, restored to -1 form below
                $this->serPtr($opArray, 'static_variables');
                $this->serPtr($opArray, 'literals');
                $this->serPtr($opArray, 'opcodes');
                $this->serPtr($opArray, 'arg_info');
                $this->serPtr($opArray, 'vars');
                $this->serStr($opArray, 'function_name');
                $this->serStr($opArray, 'filename');
                $this->serPtr($opArray, 'live_range');
                $this->serPtr($opArray, 'scope');
                $this->serStr($opArray, 'doc_comment');
                $this->serializeAttributes($opArray, 'attributes');
                $this->serPtr($opArray, 'try_catch_array');
                $this->serPtr($opArray, 'prototype');
                $this->serPtr($opArray, 'prop_info');

                return;
            }
            $this->sharedOpcodes[$opcodesAddress] = true;
        }

        if ($this->ptrValue($opArray, 'static_variables') !== 0) {
            $address = $this->serPtr($opArray, 'static_variables');
            $this->serializeHash(Core::pointerAtAddress('zend_array *', $address), $this->serializeZval(...));
        }
        if ($this->ptrValue($opArray, 'literals') !== 0) {
            $address  = $this->serPtr($opArray, 'literals');
            $zvalSize = Core::sizeOfType(zval::class);
            for ($i = 0; $i < $opArray->last_literal; $i++) {
                $this->serializeZval(Core::pointerAtAddress('zval *', $address + $i * $zvalSize));
            }
        }
        $this->serPtr($opArray, 'opcodes');
        $this->serializeArgInfo($opArray);
        $this->serializeVars($opArray);
        if ($opArray->num_dynamic_func_defs !== 0) {
            // Store offsets but keep walking through the still-real addresses,
            // exactly like the C SERIALIZE_PTR/UNSERIALIZE_PTR pairs
            $defsAddress = $this->serPtr($opArray, 'dynamic_func_defs');
            for ($i = 0; $i < $opArray->num_dynamic_func_defs; $i++) {
                $defAddress = $this->serPtrAt($defsAddress + $i * PHP_INT_SIZE);
                $this->serializeOpArray(Core::pointerAtAddress('zend_op_array *', $defAddress));
            }
        }
        $this->serStr($opArray, 'function_name');
        $this->serStr($opArray, 'filename');
        $this->serPtr($opArray, 'live_range');
        $this->serPtr($opArray, 'scope');
        $this->serStr($opArray, 'doc_comment');
        $this->serializeAttributes($opArray, 'attributes');
        $this->serPtr($opArray, 'try_catch_array');
        $this->serPtr($opArray, 'prototype');
        $this->serPtr($opArray, 'prop_info');
    }

    /**
     * @return array{int, int} [start index, end index) for the arg_info walk
     * @param \FFI\CData $opArray
     */
    private function argInfoBounds(object $opArray): array
    {
        $count = $this->requireCount((int) $opArray->num_args, 'op_array num_args');
        $start = 0;
        if (($opArray->fn_flags & self::ZEND_ACC_HAS_RETURN_TYPE) !== 0) {
            $start = -1;
        }
        if (($opArray->fn_flags & self::ZEND_ACC_VARIADIC) !== 0) {
            $count++;
        }

        return [$start, $count];
    }
    /**
     * @param \FFI\CData $opArray
     */

    private function unserializeArgInfo(object $opArray): void
    {
        if ($this->ptrValue($opArray, 'arg_info') === 0) {
            return;
        }
        $address       = $this->unPtr($opArray, 'arg_info');
        $argInfoSize   = Core::sizeOfType(zend_arg_info::class);
        [$start, $end] = $this->argInfoBounds($opArray);
        // The array starts at arg_info[start] (start is -1 for a return type)
        $this->requireSpan($address + $start * $argInfoSize, ($end - $start) * $argInfoSize, 'op_array arg_info');
        for ($i = $start; $i < $end; $i++) {
            $arg = Core::pointerAtAddress('zend_arg_info *', $address + $i * $argInfoSize);
            if (!$this->isUnserialized($this->ptrValue($arg, 'name'))) {
                $this->unStr($arg, 'name');
            }
            $this->unserializeType($arg, 'type');
        }
    }
    /**
     * @param \FFI\CData $opArray
     */

    private function serializeArgInfo(object $opArray): void
    {
        if ($this->ptrValue($opArray, 'arg_info') === 0) {
            return;
        }
        $address       = $this->serPtr($opArray, 'arg_info');
        $argInfoSize   = Core::sizeOfType(zend_arg_info::class);
        [$start, $end] = $this->argInfoBounds($opArray);
        for ($i = $start; $i < $end; $i++) {
            $arg = Core::pointerAtAddress('zend_arg_info *', $address + $i * $argInfoSize);
            if (!$this->isSerialized($this->ptrValue($arg, 'name'))) {
                $this->serStr($arg, 'name');
            }
            $this->serializeType($arg, 'type');
        }
    }
    /**
     * @param \FFI\CData $opArray
     */

    private function unserializeVars(object $opArray): void
    {
        if ($this->ptrValue($opArray, 'vars') === 0) {
            return;
        }
        $address = $this->unPtr($opArray, 'vars');
        $count   = $this->requireCount((int) $opArray->last_var, 'op_array last_var');
        $this->requireSpan($address, $count * PHP_INT_SIZE, 'op_array vars table');
        for ($i = 0; $i < $count; $i++) {
            $slot = Core::pointerAtAddress('zend_string **', $address + $i * PHP_INT_SIZE);
            $view = Core::cast('uintptr_t *', $slot);
            if (!$this->isUnserialized((int) $view[0]) && (int) $view[0] !== 0) {
                $this->requireStringOffset((int) $view[0], 'op_array var name');
                if (((int) $view[0] & 1) !== 0) {
                    $view[0] = $this->strSectionBase + ((int) $view[0] & ~1);
                } else {
                    $view[0] = $this->base + (int) $view[0];
                }
            }
        }
    }
    /**
     * @param \FFI\CData $opArray
     */

    private function serializeVars(object $opArray): void
    {
        if ($this->ptrValue($opArray, 'vars') === 0) {
            return;
        }
        $address = $this->serPtr($opArray, 'vars');
        for ($i = 0; $i < $opArray->last_var; $i++) {
            $slot   = Core::pointerAtAddress('zend_string **', $address + $i * PHP_INT_SIZE);
            $view   = Core::cast('uintptr_t *', $slot);
            $stored = (int) $view[0];
            if ($stored === 0 || $this->isSerialized($stored)) {
                continue;
            }
            if ($stored >= $this->base && $stored < $this->base + $this->size) {
                $view[0] = $stored - $this->base;
            } else {
                $view[0] = $this->emitInterned($stored);
            }
        }
    }

    // --- classes -----------------------------------------------------------
    /**
     * @param \FFI\CData $zval
     */

    private function unserializeClass(object $zval): void
    {
        $ce = Core::pointerAtAddress('zend_class_entry *', $this->unPtr($zval->value, 'ce'));
        $this->unStr($ce, 'name');
        if ($this->ptrValue($ce, 'parent') !== 0) {
            if (($ce->ce_flags & self::ZEND_ACC_LINKED) === 0) {
                $this->unStr($ce, 'parent_name');
            } else {
                $this->unPtr($ce, 'parent');
            }
        }
        $this->unserializeHash($ce->function_table, $this->unserializeFunc(...));
        $this->unserializePropertyTable($ce, 'default_properties_table', $ce->default_properties_count);
        $this->unserializePropertyTable($ce, 'default_static_members_table', $ce->default_static_members_count);
        $this->unserializeHash($ce->constants_table, $this->unserializeClassConstant(...));
        $this->unStr($ce->info->user, 'filename');
        $this->unStr($ce, 'doc_comment');
        $this->unserializeAttributes($ce, 'attributes');
        $this->unserializeHash($ce->properties_info, $this->unserializePropInfo(...));
        $this->unserializePropInfoTable($ce);
        if ($ce->num_interfaces !== 0) {
            $this->unserializeClassNames($ce, 'interface_names', $ce->num_interfaces);
        }
        if ($ce->num_traits !== 0) {
            $this->unserializeClassNames($ce, 'trait_names', $ce->num_traits);
            $this->unserializeTraitAliases($ce);
            $this->unserializeTraitPrecedences($ce);
        }
        foreach (self::MAGIC_METHOD_FIELDS as $field) {
            $this->unPtr($ce, $field);
        }
        $this->unserializeIteratorFuncs($ce);
        // MAP_PTR / default_object_handlers / get_iterator are execution-only (skipped)
    }
    /**
     * @param \FFI\CData $zval
     */

    private function serializeClass(object $zval): void
    {
        $ce = Core::pointerAtAddress('zend_class_entry *', $this->serPtr($zval->value, 'ce'));
        $this->serStr($ce, 'name');
        if ($this->ptrValue($ce, 'parent') !== 0) {
            if (($ce->ce_flags & self::ZEND_ACC_LINKED) === 0) {
                $this->serStr($ce, 'parent_name');
            } else {
                $this->serPtr($ce, 'parent');
            }
        }
        $this->serializeHash($ce->function_table, $this->serializeFunc(...));
        $this->serializePropertyTable($ce, 'default_properties_table', $ce->default_properties_count);
        $this->serializePropertyTable($ce, 'default_static_members_table', $ce->default_static_members_count);
        $this->serializeHash($ce->constants_table, $this->serializeClassConstant(...));
        $this->serStr($ce->info->user, 'filename');
        $this->serStr($ce, 'doc_comment');
        $this->serializeAttributes($ce, 'attributes');
        $this->serializeHash($ce->properties_info, $this->serializePropInfo(...));
        $this->serializePropInfoTable($ce);
        if ($ce->num_interfaces !== 0) {
            $this->serializeClassNames($ce, 'interface_names', $ce->num_interfaces);
        }
        if ($ce->num_traits !== 0) {
            $this->serializeClassNames($ce, 'trait_names', $ce->num_traits);
            $this->serializeTraitAliases($ce);
            $this->serializeTraitPrecedences($ce);
        }
        foreach (self::MAGIC_METHOD_FIELDS as $field) {
            $this->serPtr($ce, $field);
        }
        $this->serializeIteratorFuncs($ce);
    }

    private const MAGIC_METHOD_FIELDS = [
        'constructor', 'destructor', 'clone', '__get', '__set', '__call',
        '__serialize', '__unserialize', '__isset', '__unset', '__tostring',
        '__callstatic', '__debugInfo',
    ];
    /**
     * @param \FFI\CData $ce
     */

    private function unserializePropertyTable(object $ce, string $field, int $count): void
    {
        if ($this->ptrValue($ce, $field) === 0) {
            return;
        }
        $address  = $this->unPtr($ce, $field);
        $zvalSize = Core::sizeOfType(zval::class);
        $count    = $this->requireCount($count, "class {$field} count");
        $this->requireSpan($address, $count * $zvalSize, "class {$field}");
        for ($i = 0; $i < $count; $i++) {
            $this->unserializeZval(Core::pointerAtAddress('zval *', $address + $i * $zvalSize));
        }
    }
    /**
     * @param \FFI\CData $ce
     */

    private function serializePropertyTable(object $ce, string $field, int $count): void
    {
        if ($this->ptrValue($ce, $field) === 0) {
            return;
        }
        $address  = $this->serPtr($ce, $field);
        $zvalSize = Core::sizeOfType(zval::class);
        for ($i = 0; $i < $count; $i++) {
            $this->serializeZval(Core::pointerAtAddress('zval *', $address + $i * $zvalSize));
        }
    }
    /**
     * @param \FFI\CData $ce
     */

    private function unserializePropInfoTable(object $ce): void
    {
        if ($this->ptrValue($ce, 'properties_info_table') === 0) {
            return;
        }
        $address = $this->unPtr($ce, 'properties_info_table');
        $count   = $this->requireCount((int) $ce->default_properties_count, 'default_properties_count');
        $this->requireSpan($address, $count * PHP_INT_SIZE, 'properties_info_table');
        for ($i = 0; $i < $count; $i++) {
            $slot = Core::cast('uintptr_t *', Core::pointerAtAddress('void *', $address + $i * PHP_INT_SIZE));
            if ((int) $slot[0] !== 0) {
                $this->requireOffset((int) $slot[0], 'properties_info_table entry');
                $slot[0] = $this->base + (int) $slot[0];
            }
        }
    }
    /**
     * @param \FFI\CData $ce
     */

    private function serializePropInfoTable(object $ce): void
    {
        if ($this->ptrValue($ce, 'properties_info_table') === 0) {
            return;
        }
        $address = $this->serPtr($ce, 'properties_info_table');
        for ($i = 0; $i < $ce->default_properties_count; $i++) {
            $slot   = Core::cast('uintptr_t *', Core::pointerAtAddress('void *', $address + $i * PHP_INT_SIZE));
            $stored = (int) $slot[0];
            if ($stored !== 0) {
                $slot[0] = $stored - $this->base;
            }
        }
    }
    /**
     * @param \FFI\CData $ce
     */

    private function unserializeClassNames(object $ce, string $field, int $count): void
    {
        $address  = $this->unPtr($ce, $field);
        $nameSize = Core::sizeOfType(zend_class_name::class);
        $count    = $this->requireCount($count, "class {$field} count");
        $this->requireSpan($address, $count * $nameSize, "class {$field}");
        for ($i = 0; $i < $count; $i++) {
            $name = Core::pointerAtAddress('zend_class_name *', $address + $i * $nameSize);
            $this->unStr($name, 'name');
            $this->unStr($name, 'lc_name');
        }
    }
    /**
     * @param \FFI\CData $ce
     */

    private function serializeClassNames(object $ce, string $field, int $count): void
    {
        $address  = $this->serPtr($ce, $field);
        $nameSize = Core::sizeOfType(zend_class_name::class);
        for ($i = 0; $i < $count; $i++) {
            $name = Core::pointerAtAddress('zend_class_name *', $address + $i * $nameSize);
            $this->serStr($name, 'name');
            $this->serStr($name, 'lc_name');
        }
    }

    // --- traits (the num_traits branch of zend_file_cache_(un)serialize_class)
    /**
     * @param \FFI\CData $ce
     */

    private function unserializeTraitAliases(object $ce): void
    {
        if ($this->ptrValue($ce, 'trait_aliases') === 0) {
            return;
        }
        // A NULL-terminated zend_trait_alias* array; each entry's strings follow
        $slotAddress = $this->unPtr($ce, 'trait_aliases');
        // Bound the terminator scan: each slot read must stay inside the region
        $this->requireSpan($slotAddress, PHP_INT_SIZE, 'trait_aliases array');
        while (($aliasAddress = $this->unPtrAt($slotAddress)) !== 0) {
            $alias = Core::pointerAtAddress('zend_trait_alias *', $aliasAddress);
            $this->unStr($alias->trait_method, 'method_name');
            $this->unStr($alias->trait_method, 'class_name');
            $this->unStr($alias, 'alias');
            $slotAddress += PHP_INT_SIZE;
            $this->requireSpan($slotAddress, PHP_INT_SIZE, 'trait_aliases array');
        }
    }
    /**
     * @param \FFI\CData $ce
     */

    private function serializeTraitAliases(object $ce): void
    {
        if ($this->ptrValue($ce, 'trait_aliases') === 0) {
            return;
        }
        $slotAddress = $this->serPtr($ce, 'trait_aliases');
        while (($aliasAddress = $this->serPtrAt($slotAddress)) !== 0) {
            $alias = Core::pointerAtAddress('zend_trait_alias *', $aliasAddress);
            $this->serStr($alias->trait_method, 'method_name');
            $this->serStr($alias->trait_method, 'class_name');
            $this->serStr($alias, 'alias');
            $slotAddress += PHP_INT_SIZE;
        }
    }
    /**
     * @param \FFI\CData $ce
     */

    private function unserializeTraitPrecedences(object $ce): void
    {
        if ($this->ptrValue($ce, 'trait_precedences') === 0) {
            return;
        }
        // A NULL-terminated zend_trait_precedence* array with inline exclude names
        $slotAddress = $this->unPtr($ce, 'trait_precedences');
        $this->requireSpan($slotAddress, PHP_INT_SIZE, 'trait_precedences array');
        while (($precedenceAddress = $this->unPtrAt($slotAddress)) !== 0) {
            $precedence = Core::pointerAtAddress('zend_trait_precedence *', $precedenceAddress);
            $this->unStr($precedence->trait_method, 'method_name');
            $this->unStr($precedence->trait_method, 'class_name');
            $excludeBase = Core::addressOf($precedence->exclude_class_names);
            $excludes    = $this->requireCount((int) $precedence->num_excludes, 'trait precedence num_excludes');
            $this->requireSpan($excludeBase, $excludes * PHP_INT_SIZE, 'trait precedence excludes');
            for ($j = 0; $j < $excludes; $j++) {
                $this->unStrAt($excludeBase + $j * PHP_INT_SIZE);
            }
            $slotAddress += PHP_INT_SIZE;
            $this->requireSpan($slotAddress, PHP_INT_SIZE, 'trait_precedences array');
        }
    }
    /**
     * @param \FFI\CData $ce
     */

    private function serializeTraitPrecedences(object $ce): void
    {
        if ($this->ptrValue($ce, 'trait_precedences') === 0) {
            return;
        }
        $slotAddress = $this->serPtr($ce, 'trait_precedences');
        while (($precedenceAddress = $this->serPtrAt($slotAddress)) !== 0) {
            $precedence = Core::pointerAtAddress('zend_trait_precedence *', $precedenceAddress);
            $this->serStr($precedence->trait_method, 'method_name');
            $this->serStr($precedence->trait_method, 'class_name');
            $excludeBase = Core::addressOf($precedence->exclude_class_names);
            for ($j = 0; $j < $precedence->num_excludes; $j++) {
                $this->serStrAt($excludeBase + $j * PHP_INT_SIZE);
            }
            $slotAddress += PHP_INT_SIZE;
        }
    }
    /**
     * @param \FFI\CData $zval
     */

    private function unserializePropInfo(object $zval): void
    {
        if ($this->isUnserialized($this->ptrValue($zval->value, 'ptr'))) {
            return;
        }
        $prop = Core::pointerAtAddress('zend_property_info *', $this->unPtr($zval->value, 'ptr'));
        if ($this->isUnserialized($this->ptrValue($prop, 'ce'))) {
            return;
        }
        $this->unPtr($prop, 'ce');
        $this->unStr($prop, 'name');
        if ($this->ptrValue($prop, 'doc_comment') !== 0) {
            $this->unStr($prop, 'doc_comment');
        }
        $this->unserializeAttributes($prop, 'attributes');
        $this->unPtr($prop, 'prototype');
        if ($this->ptrValue($prop, 'hooks') !== 0) {
            // zend_function*[ZEND_PROPERTY_HOOK_COUNT]: relocate the array, then
            // each non-NULL hook and its op_array (a shared body returns early)
            $hooksAddress = $this->unPtr($prop, 'hooks');
            $this->requireSpan($hooksAddress, self::PROPERTY_HOOK_COUNT * PHP_INT_SIZE, 'property hooks array');
            for ($i = 0; $i < self::PROPERTY_HOOK_COUNT; $i++) {
                $hookAddress = $this->unPtrAt($hooksAddress + $i * PHP_INT_SIZE);
                if ($hookAddress !== 0) {
                    $this->unserializeOpArray(Core::pointerAtAddress('zend_function *', $hookAddress)->op_array);
                }
            }
        }
        $this->unserializeType($prop, 'type');
    }
    /**
     * @param \FFI\CData $zval
     */

    private function serializePropInfo(object $zval): void
    {
        if ($this->isSerialized($this->ptrValue($zval->value, 'ptr'))) {
            return;
        }
        $prop = Core::pointerAtAddress('zend_property_info *', $this->serPtr($zval->value, 'ptr'));
        if ($this->isSerialized($this->ptrValue($prop, 'ce'))) {
            return;
        }
        $this->serPtr($prop, 'ce');
        $this->serStr($prop, 'name');
        if ($this->ptrValue($prop, 'doc_comment') !== 0) {
            $this->serStr($prop, 'doc_comment');
        }
        $this->serializeAttributes($prop, 'attributes');
        $this->serPtr($prop, 'prototype');
        if ($this->ptrValue($prop, 'hooks') !== 0) {
            // Offsets are stored while the walk continues through the still-real
            // addresses, mirroring the C SERIALIZE_PTR/UNSERIALIZE_PTR pairs
            $hooksAddress = $this->serPtr($prop, 'hooks');
            for ($i = 0; $i < self::PROPERTY_HOOK_COUNT; $i++) {
                $hookAddress = $this->serPtrAt($hooksAddress + $i * PHP_INT_SIZE);
                if ($hookAddress !== 0) {
                    $this->serializeOpArray(Core::pointerAtAddress('zend_function *', $hookAddress)->op_array);
                }
            }
        }
        $this->serializeType($prop, 'type');
    }
    /**
     * @param \FFI\CData $zval
     */

    private function unserializeClassConstant(object $zval): void
    {
        if ($this->isUnserialized($this->ptrValue($zval->value, 'ptr'))) {
            return;
        }
        $constant = Core::pointerAtAddress('zend_class_constant *', $this->unPtr($zval->value, 'ptr'));
        if ($this->isUnserialized($this->ptrValue($constant, 'ce'))) {
            return;
        }
        $this->unPtr($constant, 'ce');
        $this->unserializeZval($constant->value);
        if ($this->ptrValue($constant, 'doc_comment') !== 0) {
            $this->unStr($constant, 'doc_comment');
        }
        $this->unserializeAttributes($constant, 'attributes');
        $this->unserializeType($constant, 'type');
    }
    /**
     * @param \FFI\CData $zval
     */

    private function serializeClassConstant(object $zval): void
    {
        if ($this->isSerialized($this->ptrValue($zval->value, 'ptr'))) {
            return;
        }
        $constant = Core::pointerAtAddress('zend_class_constant *', $this->serPtr($zval->value, 'ptr'));
        if ($this->isSerialized($this->ptrValue($constant, 'ce'))) {
            return;
        }
        $this->serPtr($constant, 'ce');
        $this->serializeZval($constant->value);
        if ($this->ptrValue($constant, 'doc_comment') !== 0) {
            $this->serStr($constant, 'doc_comment');
        }
        $this->serializeAttributes($constant, 'attributes');
        $this->serializeType($constant, 'type');
    }
    /**
     * @param \FFI\CData $ce
     */

    /**
     * zf_* field order matches the C walk (zend_file_cache.c), not the struct layout.
     * Only linked classes carry these structs - a plain compile stores classes
     * unlinked with both pointers NULL - but payloads from other producers (e.g.
     * preload-era images) may hold them, and the walk must be faithful when they do.
     */
    private const ITERATOR_FUNC_FIELDS    = ['zf_new_iterator', 'zf_rewind', 'zf_valid', 'zf_key', 'zf_current', 'zf_next'];
    private const ARRAYACCESS_FUNC_FIELDS = ['zf_offsetget', 'zf_offsetexists', 'zf_offsetset', 'zf_offsetunset'];

    /**
     * The get_iterator <-> HOOKED_ITERATOR_PLACEHOLDER swap the C load path performs
     * is deliberately NOT mirrored: the image is never executed in this process, so
     * the placeholder is preserved verbatim like every other execution-only field
     * and the written file keeps the exact bytes the engine expects.
     *
     * @param \FFI\CData $ce
     */
    private function unserializeIteratorFuncs(object $ce): void
    {
        if ($this->ptrValue($ce, 'iterator_funcs_ptr') !== 0) {
            $address = $this->unPtr($ce, 'iterator_funcs_ptr');
            $funcs   = Core::pointerAtAddress('zend_class_iterator_funcs *', $address);
            foreach (self::ITERATOR_FUNC_FIELDS as $field) {
                $this->unPtr($funcs, $field);
            }
        }
        if ($this->ptrValue($ce, 'arrayaccess_funcs_ptr') !== 0) {
            $address = $this->unPtr($ce, 'arrayaccess_funcs_ptr');
            $funcs   = Core::pointerAtAddress('zend_class_arrayaccess_funcs *', $address);
            foreach (self::ARRAYACCESS_FUNC_FIELDS as $field) {
                $this->unPtr($funcs, $field);
            }
        }
    }
    /**
     * @param \FFI\CData $ce
     */

    private function serializeIteratorFuncs(object $ce): void
    {
        // The C serialize converts the zf_* members through the still-real struct
        // pointer first and the struct pointer itself last; mirrored exactly
        $iteratorAddress = $this->ptrValue($ce, 'iterator_funcs_ptr');
        if ($iteratorAddress !== 0) {
            $funcs = Core::pointerAtAddress('zend_class_iterator_funcs *', $iteratorAddress);
            foreach (self::ITERATOR_FUNC_FIELDS as $field) {
                $this->serPtr($funcs, $field);
            }
            $this->serPtr($ce, 'iterator_funcs_ptr');
        }
        $arrayAccessAddress = $this->ptrValue($ce, 'arrayaccess_funcs_ptr');
        if ($arrayAccessAddress !== 0) {
            $funcs = Core::pointerAtAddress('zend_class_arrayaccess_funcs *', $arrayAccessAddress);
            foreach (self::ARRAYACCESS_FUNC_FIELDS as $field) {
                $this->serPtr($funcs, $field);
            }
            $this->serPtr($ce, 'arrayaccess_funcs_ptr');
        }
    }

    // --- warnings / early bindings -----------------------------------------
    /**
     * @param \FFI\CData $script
     */

    private function unserializeWarnings(object $script): void
    {
        if ($this->ptrValue($script, 'warnings') === 0) {
            return;
        }
        $address = $this->unPtr($script, 'warnings');
        $count   = $this->requireCount((int) $script->num_warnings, 'num_warnings');
        $this->requireSpan($address, $count * PHP_INT_SIZE, 'warnings table');
        for ($i = 0; $i < $count; $i++) {
            $slot = Core::cast('uintptr_t *', Core::pointerAtAddress('void *', $address + $i * PHP_INT_SIZE));
            $this->requireOffset((int) $slot[0], 'warning entry');
            $slot[0] = $this->base + (int) $slot[0];
            $warning = Core::pointerAtAddress('zend_error_info *', (int) $slot[0]);
            $this->unStr($warning, 'filename');
            $this->unStr($warning, 'message');
        }
    }
    /**
     * @param \FFI\CData $script
     */

    private function serializeWarnings(object $script): void
    {
        if ($this->ptrValue($script, 'warnings') === 0) {
            return;
        }
        $address = $this->serPtr($script, 'warnings');
        for ($i = 0; $i < $script->num_warnings; $i++) {
            $slot     = Core::cast('uintptr_t *', Core::pointerAtAddress('void *', $address + $i * PHP_INT_SIZE));
            $warnAddr = (int) $slot[0];
            $slot[0]  = $warnAddr - $this->base;
            $warning  = Core::pointerAtAddress('zend_error_info *', $warnAddr);
            $this->serStr($warning, 'filename');
            $this->serStr($warning, 'message');
        }
    }
    /**
     * @param \FFI\CData $script
     */

    private function unserializeEarlyBindings(object $script): void
    {
        if ($this->ptrValue($script, 'early_bindings') === 0) {
            return;
        }
        $address     = $this->unPtr($script, 'early_bindings');
        $bindingSize = Core::sizeOfType(zend_early_binding::class);
        $count       = $this->requireCount((int) $script->num_early_bindings, 'num_early_bindings');
        $this->requireSpan($address, $count * $bindingSize, 'early_bindings table');
        for ($i = 0; $i < $count; $i++) {
            $binding = Core::pointerAtAddress('zend_early_binding *', $address + $i * $bindingSize);
            $this->unStr($binding, 'lcname');
            $this->unStr($binding, 'rtd_key');
            $this->unStr($binding, 'lc_parent_name');
        }
    }
    /**
     * @param \FFI\CData $script
     */

    private function serializeEarlyBindings(object $script): void
    {
        if ($this->ptrValue($script, 'early_bindings') === 0) {
            return;
        }
        $address     = $this->serPtr($script, 'early_bindings');
        $bindingSize = Core::sizeOfType(zend_early_binding::class);
        for ($i = 0; $i < $script->num_early_bindings; $i++) {
            $binding = Core::pointerAtAddress('zend_early_binding *', $address + $i * $bindingSize);
            $this->serStr($binding, 'lcname');
            $this->serStr($binding, 'rtd_key');
            $this->serStr($binding, 'lc_parent_name');
        }
    }
}
