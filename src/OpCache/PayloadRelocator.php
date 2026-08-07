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

/**
 * Turns the position-independent file-cache payload into a live in-memory
 * image and back, a faithful port of ext/opcache/zend_file_cache.c
 * (unserialize = {@see relocate}, serialize = {@see derelocate}) for the
 * linux-x64 non-thread-safe build. Thread-safe (ZTS) payloads use a different
 * binary layout and are rejected until issue #118 lands ZTS-specific walking.
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
    private const IS_STRING       = 6;
    private const IS_ARRAY        = 7;
    private const IS_INDIRECT     = 12;
    private const IS_CONSTANT_AST = 11;

    private const HASH_FLAG_UNINITIALIZED = 8;
    private const HASH_FLAG_PACKED        = 4;

    private const ZEND_ACC_LINKED          = 0x8;
    private const ZEND_ACC_HAS_RETURN_TYPE = 0x2000;
    private const ZEND_ACC_VARIADIC        = 0x4000;

    private const ZEND_AST_ZVAL           = 64;
    private const ZEND_AST_CONSTANT       = 65;
    private const ZEND_AST_IS_LIST_SHIFT  = 7;
    private const ZEND_AST_CHILDREN_SHIFT = 8;

    /** zend_type bit layout (zend_types.h) - list/name discriminators */
    private const TYPE_LIST_BIT = 4194304;  // _ZEND_TYPE_LIST_BIT
    private const TYPE_NAME_BIT = 16777216; // _ZEND_TYPE_NAME_BIT

    private readonly int $base;
    private readonly int $size;
    private readonly int $strSectionBase;

    /** Interned-string re-emission state (write path) */
    private string $strSection = '';
    /** @var array<int, int> address of an interned string => its tagged offset */
    private array $internedXlat = [];
    /** @var array<int, true> opcodes address => already-serialized guard for shared method bodies */
    private array $sharedOpcodes = [];

    private readonly int $zendStringHeaderSize;

    /**
     * @param CData         $buffer   char[mem_size + str_size] holding the raw payload
     * @param CacheMetaInfo $metaInfo Parsed header describing the buffer regions
     */
    public function __construct(private readonly CData $buffer, private readonly CacheMetaInfo $metaInfo)
    {
        if (PHP_INT_SIZE !== 8 || \DIRECTORY_SEPARATOR !== '/') {
            throw OpCacheException::unsupportedPayload('the relocator supports 64-bit non-Windows builds only');
        }
        if (\ZEND_THREAD_SAFE) {
            throw OpCacheException::unsupportedPayload(
                'ZTS file-cache payloads use a different binary layout - tracked in issue #118',
            );
        }
        $this->base           = Core::addressOf(Core::addr($buffer));
        $this->size           = $metaInfo->memSize();
        $this->strSectionBase = $this->base + $this->size;
        // _ZSTR_HEADER_SIZE = sizeof(zend_string) - sizeof(char) (the flexible val[1] member)
        $this->zendStringHeaderSize = Core::sizeof(Core::type('zend_string')) - 1;
    }

    /**
     * Rewrites the buffer in place, converting every stored offset to a real
     * address, and returns a typed pointer to the embedded zend_persistent_script.
     */
    public function relocate(): CData
    {
        $this->sharedOpcodes = [];
        $script              = Core::pointerAtAddress('zend_persistent_script *', $this->base + $this->metaInfo->scriptOffset());

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

        return $memRegion . $this->strSection;
    }

    // --- pointer/offset primitives (SERIALIZE_PTR / UNSERIALIZE_PTR) --------

    /**
     * Reads a pointer field's stored value through a raw integer view of its
     * storage, so it works for every pointee type including void* (which
     * Core::addressOf cannot cast). 0 when the C NULL surfaces as PHP null.
     */
    private function ptrValue(CData $owner, string $field): int
    {
        if ($owner->$field === null) {
            return 0;
        }

        return (int) Core::cast('uintptr_t *', FFI::addr($owner->$field))[0];
    }

    private function writePtrField(CData $owner, string $field, int $address): void
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

    /** UNSERIALIZE_PTR on a struct field, returning the resolved address (0 if null) */
    private function unPtr(CData $owner, string $field): int
    {
        $stored = $this->ptrValue($owner, $field);
        if ($stored === 0) {
            return 0;
        }
        $address = $this->base + $stored;
        $this->writePtrField($owner, $field, $address);

        return $address;
    }

    /** SERIALIZE_PTR on a struct field, returning the pre-serialization address (0 if null) */
    private function serPtr(CData $owner, string $field): int
    {
        $address = $this->ptrValue($owner, $field);
        if ($address === 0) {
            return 0;
        }
        $this->writePtrField($owner, $field, $address - $this->base);

        return $address;
    }

    // --- interned-string primitives (UNSERIALIZE_STR / SERIALIZE_STR) ------

    private function unStr(CData $owner, string $field): void
    {
        $stored = $this->ptrValue($owner, $field);
        if ($stored === 0) {
            return;
        }
        if (($stored & 1) !== 0) {
            // Tagged interned reference into the string section
            $address = $this->strSectionBase + ($stored & ~1);
        } else {
            $address = $this->base + $stored;
        }
        // GC flag normalization is deliberately skipped (see class docblock)
        $this->writePtrField($owner, $field, $address);
    }

    private function serStr(CData $owner, string $field): void
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

    private function unserializeHash(CData $ht, callable $each): void
    {
        if (($ht->u->flags & self::HASH_FLAG_UNINITIALIZED) !== 0) {
            return;
        }
        if ($this->isUnserialized($this->ptrValue($ht, 'arData'))) {
            return;
        }
        $dataAddress = $this->unPtr($ht, 'arData');
        $used        = $ht->nNumUsed;
        if (($ht->u->flags & self::HASH_FLAG_PACKED) !== 0) {
            for ($i = 0; $i < $used; $i++) {
                $zval = Core::pointerAtAddress('zval *', $dataAddress + $i * Core::sizeof(Core::type('zval')));
                if ($zval->u1->v->type !== 0) {
                    $each($zval);
                }
            }

            return;
        }
        $bucketSize = Core::sizeof(Core::type('Bucket'));
        for ($i = 0; $i < $used; $i++) {
            $bucket = Core::pointerAtAddress('Bucket *', $dataAddress + $i * $bucketSize);
            if ($bucket->val->u1->v->type !== 0) {
                $this->unStr($bucket, 'key');
                $each($bucket->val);
            }
        }
    }

    private function serializeHash(CData $ht, callable $each): void
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
            for ($i = 0; $i < $used; $i++) {
                $zval = Core::pointerAtAddress('zval *', $dataAddress + $i * Core::sizeof(Core::type('zval')));
                if ($zval->u1->v->type !== 0) {
                    $each($zval);
                }
            }

            return;
        }
        $bucketSize = Core::sizeof(Core::type('Bucket'));
        for ($i = 0; $i < $used; $i++) {
            $bucket = Core::pointerAtAddress('Bucket *', $dataAddress + $i * $bucketSize);
            if ($bucket->val->u1->v->type !== 0) {
                $this->serStr($bucket, 'key');
                $each($bucket->val);
            }
        }
    }

    // --- zvals -------------------------------------------------------------

    private function unserializeZval(CData $zval): void
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
                    $this->unserializeAst($astRef + Core::sizeof(Core::type('zend_ast_ref')));
                }
                break;
            case self::IS_INDIRECT:
                $this->unPtr($zval->value, 'zv');
                break;
        }
    }

    private function serializeZval(CData $zval): void
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
                    $this->serializeAst($astRef + Core::sizeof(Core::type('zend_ast_ref')));
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
        $ast  = Core::pointerAtAddress('zend_ast *', $astAddress);
        $kind = $ast->kind;
        if ($kind === self::ZEND_AST_ZVAL || $kind === self::ZEND_AST_CONSTANT) {
            $this->unserializeZval(Core::pointerAtAddress('zend_ast_zval *', $astAddress)->val);

            return;
        }
        if (($kind >> self::ZEND_AST_IS_LIST_SHIFT & 1) !== 0) {
            $list      = Core::pointerAtAddress('zend_ast_list *', $astAddress);
            $childBase = $astAddress + Core::sizeof(Core::type('zend_ast_list')) - PHP_INT_SIZE;
            $count     = $list->children;
        } else {
            $childBase = $astAddress + Core::sizeof(Core::type('zend_ast')) - PHP_INT_SIZE;
            $count     = $kind >> self::ZEND_AST_CHILDREN_SHIFT;
        }
        for ($i = 0; $i < $count; $i++) {
            $slot  = Core::cast('uintptr_t *', Core::pointerAtAddress('void *', $childBase + $i * PHP_INT_SIZE));
            $child = (int) $slot[0];
            if ($child !== 0 && !$this->isUnserialized($child)) {
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
            $childBase = $astAddress + Core::sizeof(Core::type('zend_ast_list')) - PHP_INT_SIZE;
            $count     = $list->children;
        } else {
            $childBase = $astAddress + Core::sizeof(Core::type('zend_ast')) - PHP_INT_SIZE;
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

    private function unserializeAttributes(CData $owner, string $field): void
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

    private function serializeAttributes(CData $owner, string $field): void
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

    private function unserializeAttribute(CData $zval): void
    {
        $attr = Core::pointerAtAddress('zend_attribute *', $this->unPtr($zval->value, 'ptr'));
        $this->unStr($attr, 'name');
        $this->unStr($attr, 'lcname');
        $argSize = Core::sizeof(Core::type('zend_attribute_arg'));
        $argBase = Core::addressOf($attr->args);
        for ($i = 0; $i < $attr->argc; $i++) {
            $arg = Core::pointerAtAddress('zend_attribute_arg *', $argBase + $i * $argSize);
            $this->unStr($arg, 'name');
            $this->unserializeZval($arg->value);
        }
    }

    private function serializeAttribute(CData $zval): void
    {
        $address = $this->serPtr($zval->value, 'ptr');
        $attr    = Core::pointerAtAddress('zend_attribute *', $address);
        $this->serStr($attr, 'name');
        $this->serStr($attr, 'lcname');
        $argSize = Core::sizeof(Core::type('zend_attribute_arg'));
        $argBase = Core::addressOf($attr->args);
        for ($i = 0; $i < $attr->argc; $i++) {
            $arg = Core::pointerAtAddress('zend_attribute_arg *', $argBase + $i * $argSize);
            $this->serStr($arg, 'name');
            $this->serializeZval($arg->value);
        }
    }

    // --- types (zend_type name/list) ---------------------------------------

    private function unserializeType(CData $owner, string $field): void
    {
        $typeMask = $owner->$field->type_mask;
        if (($typeMask & self::TYPE_LIST_BIT) !== 0) {
            throw OpCacheException::unsupportedPayload('intersection/union type-list relocation');
        }
        if (($typeMask & self::TYPE_NAME_BIT) !== 0) {
            $this->unStr($owner->$field, 'ptr');
        }
    }

    private function serializeType(CData $owner, string $field): void
    {
        $typeMask = $owner->$field->type_mask;
        if (($typeMask & self::TYPE_LIST_BIT) !== 0) {
            throw OpCacheException::unsupportedPayload('intersection/union type-list relocation');
        }
        if (($typeMask & self::TYPE_NAME_BIT) !== 0) {
            $this->serStr($owner->$field, 'ptr');
        }
    }

    // --- op_array (the executable body) ------------------------------------

    private function unserializeFunc(CData $zval): void
    {
        $func = Core::pointerAtAddress('zend_function *', $this->unPtr($zval->value, 'func'));
        $this->unserializeOpArray($func->op_array);
    }

    private function serializeFunc(CData $zval): void
    {
        $func = Core::pointerAtAddress('zend_function *', $this->serPtr($zval->value, 'func'));
        $this->serializeOpArray($func->op_array);
    }

    private function unserializeOpArray(CData $opArray): void
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
            $zvalSize = Core::sizeof(Core::type('zval'));
            for ($i = 0; $i < $opArray->last_literal; $i++) {
                $this->unserializeZval(Core::pointerAtAddress('zval *', $address + $i * $zvalSize));
            }
        }
        // opcodes: only the array pointer is relocated; per-opline operands and
        // handlers are literal indexes/relative jumps on this platform and are
        // preserved verbatim (see class docblock)
        $this->unPtr($opArray, 'opcodes');
        $this->unPtr($opArray, 'scope');
        $this->unserializeArgInfo($opArray);
        $this->unserializeVars($opArray);
        if ($opArray->num_dynamic_func_defs !== 0) {
            throw OpCacheException::unsupportedPayload('dynamic function definitions (closures/arrow fns) relocation');
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

    private function serializeOpArray(CData $opArray): void
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
            $zvalSize = Core::sizeof(Core::type('zval'));
            for ($i = 0; $i < $opArray->last_literal; $i++) {
                $this->serializeZval(Core::pointerAtAddress('zval *', $address + $i * $zvalSize));
            }
        }
        $this->serPtr($opArray, 'opcodes');
        $this->serializeArgInfo($opArray);
        $this->serializeVars($opArray);
        if ($opArray->num_dynamic_func_defs !== 0) {
            throw OpCacheException::unsupportedPayload('dynamic function definitions (closures/arrow fns) relocation');
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
     */
    private function argInfoBounds(CData $opArray): array
    {
        $count = (int) $opArray->num_args;
        $start = 0;
        if (($opArray->fn_flags & self::ZEND_ACC_HAS_RETURN_TYPE) !== 0) {
            $start = -1;
        }
        if (($opArray->fn_flags & self::ZEND_ACC_VARIADIC) !== 0) {
            $count++;
        }

        return [$start, $count];
    }

    private function unserializeArgInfo(CData $opArray): void
    {
        if ($this->ptrValue($opArray, 'arg_info') === 0) {
            return;
        }
        $address       = $this->unPtr($opArray, 'arg_info');
        $argInfoSize   = Core::sizeof(Core::type('zend_arg_info'));
        [$start, $end] = $this->argInfoBounds($opArray);
        for ($i = $start; $i < $end; $i++) {
            $arg = Core::pointerAtAddress('zend_arg_info *', $address + $i * $argInfoSize);
            if (!$this->isUnserialized($this->ptrValue($arg, 'name'))) {
                $this->unStr($arg, 'name');
            }
            $this->unserializeType($arg, 'type');
        }
    }

    private function serializeArgInfo(CData $opArray): void
    {
        if ($this->ptrValue($opArray, 'arg_info') === 0) {
            return;
        }
        $address       = $this->serPtr($opArray, 'arg_info');
        $argInfoSize   = Core::sizeof(Core::type('zend_arg_info'));
        [$start, $end] = $this->argInfoBounds($opArray);
        for ($i = $start; $i < $end; $i++) {
            $arg = Core::pointerAtAddress('zend_arg_info *', $address + $i * $argInfoSize);
            if (!$this->isSerialized($this->ptrValue($arg, 'name'))) {
                $this->serStr($arg, 'name');
            }
            $this->serializeType($arg, 'type');
        }
    }

    private function unserializeVars(CData $opArray): void
    {
        if ($this->ptrValue($opArray, 'vars') === 0) {
            return;
        }
        $address = $this->unPtr($opArray, 'vars');
        for ($i = 0; $i < $opArray->last_var; $i++) {
            $slot = Core::pointerAtAddress('zend_string **', $address + $i * PHP_INT_SIZE);
            $view = Core::cast('uintptr_t *', $slot);
            if (!$this->isUnserialized((int) $view[0]) && (int) $view[0] !== 0) {
                if (((int) $view[0] & 1) !== 0) {
                    $view[0] = $this->strSectionBase + ((int) $view[0] & ~1);
                } else {
                    $view[0] = $this->base + (int) $view[0];
                }
            }
        }
    }

    private function serializeVars(CData $opArray): void
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

    private function unserializeClass(CData $zval): void
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
            throw OpCacheException::unsupportedPayload('trait-using class relocation');
        }
        foreach (self::MAGIC_METHOD_FIELDS as $field) {
            $this->unPtr($ce, $field);
        }
        $this->unserializeIteratorFuncs($ce);
        // MAP_PTR / default_object_handlers / get_iterator are execution-only (skipped)
    }

    private function serializeClass(CData $zval): void
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
            throw OpCacheException::unsupportedPayload('trait-using class relocation');
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

    private function unserializePropertyTable(CData $ce, string $field, int $count): void
    {
        if ($this->ptrValue($ce, $field) === 0) {
            return;
        }
        $address  = $this->unPtr($ce, $field);
        $zvalSize = Core::sizeof(Core::type('zval'));
        for ($i = 0; $i < $count; $i++) {
            $this->unserializeZval(Core::pointerAtAddress('zval *', $address + $i * $zvalSize));
        }
    }

    private function serializePropertyTable(CData $ce, string $field, int $count): void
    {
        if ($this->ptrValue($ce, $field) === 0) {
            return;
        }
        $address  = $this->serPtr($ce, $field);
        $zvalSize = Core::sizeof(Core::type('zval'));
        for ($i = 0; $i < $count; $i++) {
            $this->serializeZval(Core::pointerAtAddress('zval *', $address + $i * $zvalSize));
        }
    }

    private function unserializePropInfoTable(CData $ce): void
    {
        if ($this->ptrValue($ce, 'properties_info_table') === 0) {
            return;
        }
        $address = $this->unPtr($ce, 'properties_info_table');
        for ($i = 0; $i < $ce->default_properties_count; $i++) {
            $slot = Core::cast('uintptr_t *', Core::pointerAtAddress('void *', $address + $i * PHP_INT_SIZE));
            if ((int) $slot[0] !== 0) {
                $slot[0] = $this->base + (int) $slot[0];
            }
        }
    }

    private function serializePropInfoTable(CData $ce): void
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

    private function unserializeClassNames(CData $ce, string $field, int $count): void
    {
        $address  = $this->unPtr($ce, $field);
        $nameSize = Core::sizeof(Core::type('zend_class_name'));
        for ($i = 0; $i < $count; $i++) {
            $name = Core::pointerAtAddress('zend_class_name *', $address + $i * $nameSize);
            $this->unStr($name, 'name');
            $this->unStr($name, 'lc_name');
        }
    }

    private function serializeClassNames(CData $ce, string $field, int $count): void
    {
        $address  = $this->serPtr($ce, $field);
        $nameSize = Core::sizeof(Core::type('zend_class_name'));
        for ($i = 0; $i < $count; $i++) {
            $name = Core::pointerAtAddress('zend_class_name *', $address + $i * $nameSize);
            $this->serStr($name, 'name');
            $this->serStr($name, 'lc_name');
        }
    }

    private function unserializePropInfo(CData $zval): void
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
            throw OpCacheException::unsupportedPayload('property-hook relocation');
        }
        $this->unserializeType($prop, 'type');
    }

    private function serializePropInfo(CData $zval): void
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
            throw OpCacheException::unsupportedPayload('property-hook relocation');
        }
        $this->serializeType($prop, 'type');
    }

    private function unserializeClassConstant(CData $zval): void
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

    private function serializeClassConstant(CData $zval): void
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

    private function unserializeIteratorFuncs(CData $ce): void
    {
        if ($this->ptrValue($ce, 'iterator_funcs_ptr') !== 0) {
            throw OpCacheException::unsupportedPayload('iterator-aware class relocation');
        }
        if ($this->ptrValue($ce, 'arrayaccess_funcs_ptr') !== 0) {
            throw OpCacheException::unsupportedPayload('ArrayAccess class relocation');
        }
    }

    private function serializeIteratorFuncs(CData $ce): void
    {
        if ($this->ptrValue($ce, 'iterator_funcs_ptr') !== 0) {
            throw OpCacheException::unsupportedPayload('iterator-aware class relocation');
        }
        if ($this->ptrValue($ce, 'arrayaccess_funcs_ptr') !== 0) {
            throw OpCacheException::unsupportedPayload('ArrayAccess class relocation');
        }
    }

    // --- warnings / early bindings -----------------------------------------

    private function unserializeWarnings(CData $script): void
    {
        if ($this->ptrValue($script, 'warnings') === 0) {
            return;
        }
        $address = $this->unPtr($script, 'warnings');
        for ($i = 0; $i < $script->num_warnings; $i++) {
            $slot    = Core::cast('uintptr_t *', Core::pointerAtAddress('void *', $address + $i * PHP_INT_SIZE));
            $slot[0] = $this->base + (int) $slot[0];
            $warning = Core::pointerAtAddress('zend_error_info *', (int) $slot[0]);
            $this->unStr($warning, 'filename');
            $this->unStr($warning, 'message');
        }
    }

    private function serializeWarnings(CData $script): void
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

    private function unserializeEarlyBindings(CData $script): void
    {
        if ($this->ptrValue($script, 'early_bindings') === 0) {
            return;
        }
        $address     = $this->unPtr($script, 'early_bindings');
        $bindingSize = Core::sizeof(Core::type('zend_early_binding'));
        for ($i = 0; $i < $script->num_early_bindings; $i++) {
            $binding = Core::pointerAtAddress('zend_early_binding *', $address + $i * $bindingSize);
            $this->unStr($binding, 'lcname');
            $this->unStr($binding, 'rtd_key');
            $this->unStr($binding, 'lc_parent_name');
        }
    }

    private function serializeEarlyBindings(CData $script): void
    {
        if ($this->ptrValue($script, 'early_bindings') === 0) {
            return;
        }
        $address     = $this->serPtr($script, 'early_bindings');
        $bindingSize = Core::sizeof(Core::type('zend_early_binding'));
        for ($i = 0; $i < $script->num_early_bindings; $i++) {
            $binding = Core::pointerAtAddress('zend_early_binding *', $address + $i * $bindingSize);
            $this->serStr($binding, 'lcname');
            $this->serStr($binding, 'rtd_key');
            $this->serStr($binding, 'lc_parent_name');
        }
    }
}
