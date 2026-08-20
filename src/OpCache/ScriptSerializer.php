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

namespace ZEngine\OpCache;

use FFI;
use FFI\CData;
use ZEngine\Core;
use ZEngine\Generated\Bucket;
use ZEngine\Generated\zend_arg_info;
use ZEngine\Generated\zend_ast;
use ZEngine\Generated\zend_ast_list;
use ZEngine\Generated\zend_ast_ref;
use ZEngine\Generated\zend_attribute;
use ZEngine\Generated\zend_attribute_arg;
use ZEngine\Generated\zend_class_arrayaccess_funcs;
use ZEngine\Generated\zend_class_constant;
use ZEngine\Generated\zend_class_entry;
use ZEngine\Generated\zend_class_iterator_funcs;
use ZEngine\Generated\zend_class_name;
use ZEngine\Generated\zend_early_binding;
use ZEngine\Generated\zend_error_info;
use ZEngine\Generated\zend_live_range;
use ZEngine\Generated\zend_op_array;
use ZEngine\Generated\zend_persistent_script;
use ZEngine\Generated\zend_property_info;
use ZEngine\Generated\zend_string;
use ZEngine\Generated\zend_trait_alias;
use ZEngine\Generated\zend_trait_precedence;
use ZEngine\Generated\zend_try_catch_element;
use ZEngine\Generated\zend_type;
use ZEngine\Generated\zend_type_list;
use ZEngine\Generated\zval;

/**
 * The from-scratch persist-from-graph serializer (issue #117): a two-pass port
 * of zend_persist_calc -> zend_persist fused with the offset-encoding stage of
 * zend_file_cache_serialize, for graphs that grew beyond the original buffer
 * (added functions/methods, regrown hashtables, replaced sub-arrays).
 *
 * Pass 1 walks the (possibly mutated) live graph rooted at the
 * zend_persistent_script, deduplicating every reachable allocation unit through
 * an xlat table (the port of zend_shared_alloc_get/register_xlat_entry) and
 * computing the total ZEND_MM_ALIGNED size, exactly like zend_persist_calc.
 * Pass 2 emits a fresh contiguous buffer: every unit is copied byte-verbatim to
 * its assigned offset and every pointer field is rewritten to the copy's new
 * address - producing a valid RELOCATED image, whose conversion to the on-disk
 * offset form is then delegated to the proven {@see PayloadRelocator}
 * (serialize = derelocate), so the offset/interning encoding has exactly one
 * implementation.
 *
 * Two deliberate simplifications against zend_persist.c, both valid per the
 * file-cache format:
 *
 * - every zend_string is copied into the mem region (the compile child in
 *   file_cache_only mode does the same: nothing is accel-interned there, so
 *   zend_accel_store_interned_string region-copies every string). The emitted
 *   image therefore carries an empty interned-string section, and any string
 *   whose source lacks the interned GC bits gets them stamped on the copy -
 *   the port of zend_set_str_gc_flags' file_cache_only branch;
 * - sparse hashtables are copied as-is instead of compacted (zend_hash_persist
 *   compacts as an optimization only; mask/index invariants are preserved
 *   either way).
 *
 * Inputs must be persisted images: the walkers copy payload bytes verbatim, so
 * every op_array reachable from the graph must already be in file form (opline
 * handlers as table indexes, IS_CONST operands as literal indexes) - which is
 * true for anything that came out of a cache binary, including grafts pulled
 * from a donor binary compiled by a real opcache child. Freshly in-process
 * compiled op_arrays are NOT accepted implicitly; grafting goes through
 * {@see ReflectionOpcacheFile::addFunctionFrom()} / addMethodFrom(), which only
 * take donors from other cache binaries.
 *
 * @internal core-layer machinery, constructed by BinaryCacheFile::save()
 */
final class ScriptSerializer
{
    private const int IS_STRING       = 6;
    private const int IS_ARRAY        = 7;
    private const int IS_CONSTANT_AST = 11;
    private const int IS_INDIRECT     = 12;

    private const int ZEND_AST_ZVAL           = 64;
    private const int ZEND_AST_CONSTANT       = 65;
    private const int ZEND_AST_IS_LIST_SHIFT  = 7;
    private const int ZEND_AST_CHILDREN_SHIFT = 8;

    /** zend_type bit layout (zend_types.h) - list/name discriminators */
    private const int TYPE_LIST_BIT = 4194304;  // _ZEND_TYPE_LIST_BIT
    private const int TYPE_NAME_BIT = 16777216; // _ZEND_TYPE_NAME_BIT

    /** ZEND_PROPERTY_HOOK_COUNT (zend_property_hooks.h) - get + set slots */
    private const int PROPERTY_HOOK_COUNT = 2;

    private const MAGIC_METHOD_FIELDS = [
        'constructor', 'destructor', 'clone', '__get', '__set', '__call',
        '__serialize', '__unserialize', '__isset', '__unset', '__tostring',
        '__callstatic', '__debugInfo',
    ];
    private const ITERATOR_FUNC_FIELDS    = ['zf_new_iterator', 'zf_rewind', 'zf_valid', 'zf_key', 'zf_current', 'zf_next'];
    private const ARRAYACCESS_FUNC_FIELDS = ['zf_offsetget', 'zf_offsetexists', 'zf_offsetset', 'zf_offsetunset'];

    /** 1 = measure (zend_persist_calc), 2 = emit (zend_persist + file-cache encode) */
    private int $phase = 1;

    /** @var array<int, int> source unit address => offset in the new region (the xlat table) */
    private array $xlat = [];
    /** @var list<int> sorted source unit start addresses (interior-pointer resolution) */
    private array $unitStarts = [];
    /** @var array<int, int> source unit start => byte size */
    private array $unitSizes = [];
    /** @var array<int, true> source unit address => copied guard (pass 2) */
    private array $copied = [];
    /**
     * Pointer fields whose target unit may not be translated yet at emit time
     * (prototypes, scopes, prop_info back-references, magic-method slots ...):
     * resolved against the finished xlat after the walk, like the late
     * zend_shared_alloc_get_xlat_entry lookups in zend_persist.c.
     *
     * @var list<array{int, int, string}> [slot address in the copy, source target, description]
     */
    private array $deferred = [];

    private int $total = 0;
    /** @var CData|null the emitted buffer (kept alive by the instance) */
    private ?CData $out  = null;
    private int $newBase = 0;

    private readonly int $zendStringHeaderSize;

    /**
     * @param CData $script the relocated zend_persistent_script* of the live image
     */
    public function __construct(private readonly object $script)
    {
        if (!PayloadRelocator::isSupported()) {
            throw OpCacheException::unsupportedPayload('the graph serializer supports 64-bit POSIX builds only');
        }
        // _ZSTR_HEADER_SIZE = XtOffsetOf(zend_string, val): the flexible val[1]
        // member starts at the last 8-byte slot of the (padded) struct
        $this->zendStringHeaderSize = Core::sizeOfType(zend_string::class) - PHP_INT_SIZE;
    }

    /**
     * Emits a fresh payload (mem region + empty string section) from the graph.
     * The source image is never written to, so the live view stays valid and
     * serialize() can be called again after further mutations.
     */
    public function serialize(): string
    {
        $scriptAddress = Core::addressOf($this->script);

        $this->phase     = 1;
        $this->xlat      = [];
        $this->unitSizes = [];
        $this->total     = 0;
        $this->persistScript($scriptAddress);

        $this->unitStarts = array_keys($this->unitSizes);
        sort($this->unitStarts);

        $this->phase    = 2;
        $this->copied   = [];
        $this->deferred = [];
        $this->out      = Core::new("char[{$this->total}]", false);
        $this->newBase  = Core::addressOf(Core::addr($this->out));
        $this->persistScript($scriptAddress);
        $this->resolveDeferred();

        // The emitted region is a valid relocated image; the on-disk offset
        // encoding is the relocator's serialize - byte-tested machinery
        $meta = CacheMetaInfo::forPayload(
            systemId: SystemId::current(),
            memSize: $this->total,
            strSize: 0,
            scriptOffset: $this->xlat[$scriptAddress],
            timestamp: 0,
            checksum: 0,
        );
        $relocator = new PayloadRelocator($this->out, $meta);

        return $relocator->derelocate();
    }

    /** Size of the emitted mem region; only meaningful after serialize() */
    public function memSize(): int
    {
        return $this->total;
    }

    /** Offset of the zend_persistent_script inside the emitted region */
    public function scriptOffset(): int
    {
        return $this->xlat[Core::addressOf($this->script)] ?? 0;
    }

    // --- unit / pointer primitives ------------------------------------------

    /**
     * Registers (pass 1) or copies (pass 2) one allocation unit.
     *
     * @return array{int, bool} [address of the copy (0 in pass 1), first visit?]
     */
    private function unit(int $source, int $size): array
    {
        if ($this->phase === 1) {
            if (isset($this->xlat[$source])) {
                return [0, false];
            }
            $this->xlat[$source]      = $this->total;
            $this->unitSizes[$source] = $size;
            $this->total += Core::getAlignedSize($size);

            return [0, true];
        }
        if (!isset($this->xlat[$source])) {
            throw OpCacheException::unresolvedGraphReference(sprintf('unit 0x%x reached only in the emit pass', $source));
        }
        $new = $this->newBase + $this->xlat[$source];
        if (isset($this->copied[$source])) {
            return [$new, false];
        }
        $this->copied[$source] = true;
        FFI::memcpy(
            Core::cast('char *', Core::pointerAtAddress('void *', $new)),
            Core::cast('char *', Core::pointerAtAddress('void *', $source)),
            $size,
        );

        return [$new, true];
    }

    /** Translates a source address to its copy, resolving interior pointers */
    private function mapAddress(int $source): int
    {
        if (isset($this->xlat[$source])) {
            return $this->newBase + $this->xlat[$source];
        }
        // Binary search for the unit containing the address
        $low  = 0;
        $high = \count($this->unitStarts) - 1;
        while ($low <= $high) {
            $mid   = ($low + $high) >> 1;
            $start = $this->unitStarts[$mid];
            if ($source < $start) {
                $high = $mid - 1;
                continue;
            }
            if ($source < $start + $this->unitSizes[$start]) {
                return $this->newBase + $this->xlat[$start] + ($source - $start);
            }
            $low = $mid + 1;
        }

        throw OpCacheException::unresolvedGraphReference(sprintf('pointer to 0x%x targets no persisted unit', $source));
    }

    /**
     * Reads a pointer field's stored value as an integer (0 for C NULL).
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
     * Writes a pointer field in the emit pass (no-op while measuring). The
     * field always holds its non-null source value at this point.
     *
     * @param \FFI\CData $owner a view into the COPY
     */
    private function put(object $owner, string $field, int $address): void
    {
        if ($this->phase !== 2) {
            return;
        }
        $slot    = Core::cast('uintptr_t *', FFI::addr($owner->$field));
        $slot[0] = $address;
    }

    /** Raw pointer-slot write in the emit pass */
    private function putAt(int $slotAddress, int $value): void
    {
        if ($this->phase !== 2) {
            return;
        }
        Core::cast('uintptr_t *', Core::pointerAtAddress('void *', $slotAddress))[0] = $value;
    }

    /** Reads a raw pointer slot */
    private function slotValue(int $slotAddress): int
    {
        return (int) Core::cast('uintptr_t *', Core::pointerAtAddress('void *', $slotAddress))[0];
    }

    /** Defers a copy-slot rewrite until the xlat table is complete */
    private function deferAt(int $slotAddress, int $sourceTarget, string $what): void
    {
        if ($this->phase !== 2) {
            return;
        }
        $this->deferred[] = [$slotAddress, $sourceTarget, $what];
    }

    /**
     * @param \FFI\CData $owner a view into the COPY, field currently non-null
     */
    private function defer(object $owner, string $field, int $sourceTarget, string $what): void
    {
        if ($this->phase !== 2) {
            return;
        }
        $this->deferred[] = [Core::addressOf(FFI::addr($owner->$field)), $sourceTarget, $what];
    }

    private function resolveDeferred(): void
    {
        foreach ($this->deferred as [$slotAddress, $sourceTarget, $what]) {
            $this->putAt($slotAddress, $this->mapAddress($sourceTarget));
        }
        $this->deferred = [];
    }

    // --- strings --------------------------------------------------------------

    /**
     * Region-copies one zend_string (zend_accel_store_interned_string for the
     * file_cache_only case: nothing is accel-interned, everything is memdup'd
     * and stamped with the interned GC bits via zend_set_str_gc_flags).
     */
    private function persistString(int $source): int
    {
        $string        = Core::pointerAtAddress('zend_string *', $source);
        $size          = $this->zendStringHeaderSize + $string->len + 1;
        [$new, $first] = $this->unit($source, $size);
        if ($first && $this->phase === 2) {
            $copy     = Core::pointerAtAddress('zend_string *', $new);
            $typeInfo = $copy->gc->u->type_info;
            if (($typeInfo & Core::engineConstant('IS_STR_INTERNED')) === 0) {
                // zend_set_str_gc_flags, file_cache_only branch
                $copy->gc->refcount     = 2;
                $copy->gc->u->type_info = Core::engineConstant('GC_STRING')
                    | Core::engineConstant('IS_STR_INTERNED')
                    | ($typeInfo & Core::engineConstant('IS_STR_VALID_UTF8'));
            }
        }

        return $new;
    }

    // --- hashtables (zend_hash_persist) ---------------------------------------

    /**
     * Persists the DATA block of a hashtable and walks its live entries; the
     * HashTable struct itself lives in its owner (embedded) or in its own unit
     * (zend_array). $entry receives [source zval address, copy zval address].
     *
     * @param \FFI\CData $ht     source HashTable view
     * @param \FFI\CData $htCopy copy HashTable view (same as $ht while measuring)
     */
    private function persistHashData(object $ht, object $htCopy, callable $entry): void
    {
        if (($ht->u->flags & Core::engineConstant('HASH_FLAG_UNINITIALIZED')) !== 0) {
            return; // arData is written as 0 by the relocator's serialize stage
        }
        $dataAddress = $this->ptrValue($ht, 'arData');
        if ($dataAddress === 0) {
            return;
        }
        $used   = $ht->nNumUsed;
        $packed = ($ht->u->flags & Core::engineConstant('HASH_FLAG_PACKED')) !== 0;
        if ($packed) {
            // Packed tables reserve HT_HASH_SIZE(HT_MIN_MASK) bytes before arData
            $hashBytes = (0x100000000 - Core::engineConstant('HT_MIN_MASK')) * 4;
            $entrySize = Core::sizeOfType(zval::class);
        } else {
            $hashBytes = (0x100000000 - $ht->nTableMask) * 4;
            $entrySize = Core::sizeOfType(Bucket::class);
        }
        $dataStart    = $dataAddress - $hashBytes;
        $usedSize     = $hashBytes + $used * $entrySize;
        [$newStart, ] = $this->unit($dataStart, $usedSize);
        $newData      = $newStart + $hashBytes;
        $this->put($htCopy, 'arData', $newData);

        for ($i = 0; $i < $used; $i++) {
            $sourceEntry = $dataAddress                  + $i * $entrySize;
            $copyEntry   = $this->phase === 2 ? $newData + $i * $entrySize : $sourceEntry;
            if ($packed) {
                $zv = Core::pointerAtAddress('zval *', $sourceEntry);
                if ($zv->u1->v->type !== 0) {
                    $entry($sourceEntry, $copyEntry);
                }
                continue;
            }
            $bucket = Core::pointerAtAddress('Bucket *', $sourceEntry);
            if ($bucket->val->u1->v->type === 0) {
                continue; // hole: bytes copied verbatim, nothing to walk
            }
            $keyAddress = $this->ptrValue($bucket, 'key');
            if ($keyAddress !== 0) {
                $newKey     = $this->persistString($keyAddress);
                $bucketCopy = Core::pointerAtAddress('Bucket *', $copyEntry);
                if ($this->phase === 2) {
                    $this->put($bucketCopy, 'key', $newKey);
                }
            }
            $entry($sourceEntry, $copyEntry);
        }
    }

    /** A pointed-to zend_array (IS_ARRAY zval, static_variables, attributes) */
    private function persistArray(int $source, callable $entry): int
    {
        [$new, $first] = $this->unit($source, Core::sizeOfType('HashTable'));
        if ($first) {
            $ht     = Core::pointerAtAddress('HashTable *', $source);
            $htCopy = $this->phase === 2 ? Core::pointerAtAddress('HashTable *', $new) : $ht;
            $this->persistHashData($ht, $htCopy, $entry);
        }

        return $new;
    }

    // --- zvals ------------------------------------------------------------------

    private function persistZval(int $source, int $copy): void
    {
        $zv     = Core::pointerAtAddress('zval *', $source);
        $zvCopy = $this->phase === 2 ? Core::pointerAtAddress('zval *', $copy) : $zv;
        switch ($zv->u1->v->type) {
            case self::IS_STRING:
                $this->put($zvCopy->value, 'str', $this->persistString($this->ptrValue($zv->value, 'str')));
                break;
            case self::IS_ARRAY:
                $new = $this->persistArray(
                    $this->ptrValue($zv->value, 'arr'),
                    fn(int $s, int $c) => $this->persistZval($s, $c),
                );
                $this->put($zvCopy->value, 'arr', $new);
                break;
            case self::IS_CONSTANT_AST:
                $this->put($zvCopy->value, 'ast', $this->persistAstRef($this->ptrValue($zv->value, 'ast')));
                break;
            case self::IS_INDIRECT:
                // Points INTO another unit (a property-table slot): interior fixup
                $this->defer($zvCopy->value, 'zv', $this->ptrValue($zv->value, 'zv'), 'IS_INDIRECT zval');
                break;
        }
    }

    // --- constant ASTs (zend_persist_ast) ----------------------------------------

    /** The zend_ast_ref unit carries the root node inline, children are units */
    private function persistAstRef(int $source): int
    {
        $rootSource    = $source                               + Core::sizeOfType(zend_ast_ref::class);
        $refSize       = Core::sizeOfType(zend_ast_ref::class) + $this->astNodeSize($rootSource);
        [$new, $first] = $this->unit($source, $refSize);
        if ($first) {
            $this->persistAstNodeBody($rootSource, $new === 0 ? 0 : $new + Core::sizeOfType(zend_ast_ref::class));
        }

        return $new;
    }

    private function persistAstNode(int $source): int
    {
        [$new, $first] = $this->unit($source, $this->astNodeSize($source));
        if ($first) {
            $this->persistAstNodeBody($source, $new);
        }

        return $new;
    }

    private function persistAstNodeBody(int $source, int $copy): void
    {
        $ast  = Core::pointerAtAddress('zend_ast *', $source);
        $kind = $ast->kind;
        if ($kind === self::ZEND_AST_ZVAL || $kind === self::ZEND_AST_CONSTANT) {
            $valueOffset = Core::sizeOfType('zend_ast_zval') - Core::sizeOfType(zval::class);
            $this->persistZval($source + $valueOffset, $copy + $valueOffset);

            return;
        }
        [$childBase, $count] = $this->astChildren($source, $kind);
        for ($i = 0; $i < $count; $i++) {
            $childSource = $this->slotValue($childBase + $i * PHP_INT_SIZE);
            if ($childSource === 0) {
                continue;
            }
            $new = $this->persistAstNode($childSource);
            $this->putAt($copy + ($childBase - $source) + $i * PHP_INT_SIZE, $new);
        }
    }

    /** @return array{int, int} [child slot base address, child count] */
    private function astChildren(int $source, int $kind): array
    {
        if (($kind >> self::ZEND_AST_IS_LIST_SHIFT & 1) !== 0) {
            $list = Core::pointerAtAddress(zend_ast_list::class, $source);

            return [$source + Core::sizeOfType(zend_ast_list::class) - PHP_INT_SIZE, $list->children];
        }

        return [$source + Core::sizeOfType(zend_ast::class) - PHP_INT_SIZE, $kind >> self::ZEND_AST_CHILDREN_SHIFT];
    }

    private function astNodeSize(int $source): int
    {
        $ast  = Core::pointerAtAddress('zend_ast *', $source);
        $kind = $ast->kind;
        if ($kind === self::ZEND_AST_ZVAL || $kind === self::ZEND_AST_CONSTANT) {
            return Core::sizeOfType('zend_ast_zval');
        }
        if (($kind >> self::ZEND_AST_IS_LIST_SHIFT & 1) !== 0) {
            $list = Core::pointerAtAddress(zend_ast_list::class, $source);

            return Core::sizeOfType(zend_ast_list::class) - PHP_INT_SIZE + PHP_INT_SIZE * $list->children;
        }

        return Core::sizeOfType(zend_ast::class) - PHP_INT_SIZE + PHP_INT_SIZE * ($kind >> self::ZEND_AST_CHILDREN_SHIFT);
    }

    // --- attributes ----------------------------------------------------------------

    private function persistAttributes(object $owner, object $ownerCopy, string $field): void
    {
        $source = $this->ptrValue($owner, $field);
        if ($source === 0) {
            return;
        }
        $new = $this->persistArray($source, function (int $zvalSource, int $zvalCopy): void {
            $zv         = Core::pointerAtAddress('zval *', $zvalSource);
            $attrSource = $this->ptrValue($zv->value, 'ptr');
            $attr       = Core::pointerAtAddress('zend_attribute *', $attrSource);
            $argSize    = Core::sizeOfType(zend_attribute_arg::class);
            // ZEND_ATTRIBUTE_SIZE(argc)
            $size          = Core::sizeOfType(zend_attribute::class) + $argSize * $attr->argc - $argSize;
            [$new, $first] = $this->unit($attrSource, $size);
            if ($this->phase === 2) {
                $this->put(Core::pointerAtAddress('zval *', $zvalCopy)->value, 'ptr', $new);
            }
            if (!$first) {
                return;
            }
            $attrCopy = $this->phase === 2 ? Core::pointerAtAddress('zend_attribute *', $new) : $attr;
            $this->put($attrCopy, 'name', $this->persistString($this->ptrValue($attr, 'name')));
            $this->put($attrCopy, 'lcname', $this->persistString($this->ptrValue($attr, 'lcname')));
            $argBase = Core::addressOf($attr->args);
            for ($i = 0; $i < $attr->argc; $i++) {
                $argSource = $argBase                                              + $i * $argSize;
                $argCopy   = $this->phase === 2 ? Core::addressOf($attrCopy->args) + $i * $argSize : $argSource;
                $arg       = Core::pointerAtAddress('zend_attribute_arg *', $argSource);
                $nameAddr  = $this->ptrValue($arg, 'name');
                if ($nameAddr !== 0) {
                    $this->put(Core::pointerAtAddress('zend_attribute_arg *', $argCopy), 'name', $this->persistString($nameAddr));
                }
                $valueOffset = $argSize - Core::sizeOfType(zval::class);
                $this->persistZval($argSource + $valueOffset, $argCopy + $valueOffset);
            }
        });
        $this->put($ownerCopy, $field, $new);
    }

    // --- types ------------------------------------------------------------------------

    /**
     * @param \FFI\CData $type     source zend_type view (embedded)
     * @param \FFI\CData $typeCopy copy zend_type view
     */
    private function persistType(object $type, object $typeCopy): void
    {
        $typeMask = $type->type_mask;
        if (($typeMask & self::TYPE_LIST_BIT) !== 0) {
            $listSource    = $this->ptrValue($type, 'ptr');
            $list          = Core::pointerAtAddress('zend_type_list *', $listSource);
            $typeSize      = Core::sizeOfType(zend_type::class);
            $entryBase     = Core::sizeOfType(zend_type_list::class) - $typeSize;
            $size          = $entryBase + $typeSize * $list->num_types;
            [$new, $first] = $this->unit($listSource, $size);
            $this->put($typeCopy, 'ptr', $new);
            if ($first) {
                for ($i = 0; $i < $list->num_types; $i++) {
                    $entrySource = Core::pointerAtAddress('zend_type *', $listSource + $entryBase + $i * $typeSize);
                    $entryCopy   = $this->phase === 2
                        ? Core::pointerAtAddress('zend_type *', $new + $entryBase + $i * $typeSize)
                        : $entrySource;
                    $this->persistType($entrySource, $entryCopy);
                }
            }

            return;
        }
        if (($typeMask & self::TYPE_NAME_BIT) !== 0) {
            $this->put($typeCopy, 'ptr', $this->persistString($this->ptrValue($type, 'ptr')));
        }
    }

    // --- op_arrays (zend_persist_op_array) -------------------------------------------------

    /** Persists a pointed-to zend_function unit (function table entries, hooks, closures) */
    private function persistFunction(int $source): int
    {
        $opArray = Core::pointerAtAddress('zend_op_array *', $source);
        if ($opArray->type !== Core::engineConstant('ZEND_USER_FUNCTION')) {
            throw OpCacheException::unsupportedPayload('only user functions can be persisted into a file-cache image');
        }
        [$new, $first] = $this->unit($source, Core::sizeOfType(zend_op_array::class));
        if ($first) {
            $this->persistOpArrayBody($source, $new);
        }

        return $new;
    }

    /** The shared field walk for pointed-to op_arrays and the embedded main_op_array */
    private function persistOpArrayBody(int $source, int $copy): void
    {
        $op     = Core::pointerAtAddress('zend_op_array *', $source);
        $opCopy = $this->phase === 2 ? Core::pointerAtAddress('zend_op_array *', $copy) : $op;

        $staticVariables = $this->ptrValue($op, 'static_variables');
        if ($staticVariables !== 0) {
            $new = $this->persistArray($staticVariables, fn(int $s, int $c) => $this->persistZval($s, $c));
            $this->put($opCopy, 'static_variables', $new);
        }

        $literals = $this->ptrValue($op, 'literals');
        if ($literals !== 0) {
            $zvalSize      = Core::sizeOfType(zval::class);
            [$new, $first] = $this->unit($literals, $op->last_literal * $zvalSize);
            $this->put($opCopy, 'literals', $new);
            if ($first) {
                for ($i = 0; $i < $op->last_literal; $i++) {
                    $this->persistZval($literals + $i * $zvalSize, $new + $i * $zvalSize);
                }
            }
        }

        $opcodes = $this->ptrValue($op, 'opcodes');
        if ($opcodes !== 0) {
            // Byte-verbatim: payload oplines are already file-form (handler
            // indexes, literal-index operands, relative jumps)
            [$new, ] = $this->unit($opcodes, $op->last * Core::sizeOfType('zend_op'));
            $this->put($opCopy, 'opcodes', $new);
        }

        $argInfo = $this->ptrValue($op, 'arg_info');
        if ($argInfo !== 0) {
            $argSize  = Core::sizeOfType(zend_arg_info::class);
            $hasRet   = ($op->fn_flags & 0x2000) !== 0 ? 1 : 0;       // ZEND_ACC_HAS_RETURN_TYPE
            $variadic = ($op->fn_flags & 0x4000) !== 0 ? 1 : 0;      // ZEND_ACC_VARIADIC
            $entries  = $op->num_args + $hasRet + $variadic;
            // The allocation starts at the return-type slot (arg_info[-1])
            $allocStart    = $argInfo - $hasRet * $argSize;
            [$new, $first] = $this->unit($allocStart, $entries * $argSize);
            $this->put($opCopy, 'arg_info', $new + $hasRet * $argSize);
            if ($first) {
                for ($i = 0; $i < $entries; $i++) {
                    $entrySource = Core::pointerAtAddress('zend_arg_info *', $allocStart + $i * $argSize);
                    $entryCopy   = $this->phase === 2
                        ? Core::pointerAtAddress('zend_arg_info *', $new + $i * $argSize)
                        : $entrySource;
                    $nameAddress = $this->ptrValue($entrySource, 'name');
                    if ($nameAddress !== 0) {
                        $this->put($entryCopy, 'name', $this->persistString($nameAddress));
                    }
                    $this->persistType($entrySource->type, $entryCopy->type);
                }
            }
        }

        $vars = $this->ptrValue($op, 'vars');
        if ($vars !== 0) {
            [$new, $first] = $this->unit($vars, $op->last_var * PHP_INT_SIZE);
            $this->put($opCopy, 'vars', $new);
            if ($first) {
                for ($i = 0; $i < $op->last_var; $i++) {
                    $stringAddress = $this->slotValue($vars + $i * PHP_INT_SIZE);
                    if ($stringAddress !== 0) {
                        $this->putAt($new + $i * PHP_INT_SIZE, $this->persistString($stringAddress));
                    }
                }
            }
        }

        foreach (['function_name', 'filename', 'doc_comment'] as $stringField) {
            $address = $this->ptrValue($op, $stringField);
            if ($address !== 0) {
                $this->put($opCopy, $stringField, $this->persistString($address));
            }
        }

        $liveRange = $this->ptrValue($op, 'live_range');
        if ($liveRange !== 0) {
            [$new, ] = $this->unit($liveRange, $op->last_live_range * Core::sizeOfType(zend_live_range::class));
            $this->put($opCopy, 'live_range', $new);
        }

        $this->persistAttributes($op, $opCopy, 'attributes');

        $tryCatch = $this->ptrValue($op, 'try_catch_array');
        if ($tryCatch !== 0) {
            [$new, ] = $this->unit($tryCatch, $op->last_try_catch * Core::sizeOfType(zend_try_catch_element::class));
            $this->put($opCopy, 'try_catch_array', $new);
        }

        if ($op->num_dynamic_func_defs !== 0) {
            $defs          = $this->ptrValue($op, 'dynamic_func_defs');
            [$new, $first] = $this->unit($defs, $op->num_dynamic_func_defs * PHP_INT_SIZE);
            $this->put($opCopy, 'dynamic_func_defs', $new);
            if ($first) {
                for ($i = 0; $i < $op->num_dynamic_func_defs; $i++) {
                    $defSource = $this->slotValue($defs + $i * PHP_INT_SIZE);
                    $this->putAt($new + $i * PHP_INT_SIZE, $this->persistFunction($defSource));
                }
            }
        }

        foreach ([
            'scope'     => 'op_array scope',
            'prototype' => 'op_array prototype',
            'prop_info' => 'op_array prop_info (hook back-reference)',
        ] as $field => $what) {
            $target = $this->ptrValue($op, $field);
            if ($target !== 0) {
                $this->defer($opCopy, $field, $target, $what);
            }
        }
        // refcount / run_time_cache / static_variables_ptr map slots are copied
        // verbatim: sources are persisted images where they already hold the
        // file-form values (NULL, or the shared-body -1 refcount marker)
    }

    // --- classes (zend_persist_class_entry, the non-LINKED branch) ---------------------------

    private function persistClassEntry(int $source): int
    {
        [$ceNew, $first] = $this->unit($source, Core::sizeOfType(zend_class_entry::class));
        if (!$first) {
            return $ceNew;
        }
        $ce     = Core::pointerAtAddress('zend_class_entry *', $source);
        $ceCopy = $this->phase === 2 ? Core::pointerAtAddress('zend_class_entry *', $ceNew) : $ce;

        $this->put($ceCopy, 'name', $this->persistString($this->ptrValue($ce, 'name')));
        if ($this->ptrValue($ce, 'parent') !== 0) {
            if (($ce->ce_flags & Core::engineConstant('ZEND_ACC_LINKED')) !== 0) {
                $this->defer($ceCopy, 'parent', $this->ptrValue($ce, 'parent'), 'linked parent class');
            } else {
                $this->put($ceCopy, 'parent_name', $this->persistString($this->ptrValue($ce, 'parent_name')));
            }
        }

        $this->persistHashData($ce->function_table, $ceCopy->function_table, function (int $zvalSource, int $zvalCopy): void {
            $zv  = Core::pointerAtAddress('zval *', $zvalSource);
            $new = $this->persistFunction($this->ptrValue($zv->value, 'func'));
            if ($this->phase === 2) {
                $this->put(Core::pointerAtAddress('zval *', $zvalCopy)->value, 'func', $new);
            }
        });

        foreach ([
            'default_properties_table'     => $ce->default_properties_count,
            'default_static_members_table' => $ce->default_static_members_count,
        ] as $tableField => $count) {
            $table = $this->ptrValue($ce, $tableField);
            if ($table === 0) {
                continue;
            }
            $zvalSize      = Core::sizeOfType(zval::class);
            [$new, $first] = $this->unit($table, $count * $zvalSize);
            $this->put($ceCopy, $tableField, $new);
            if ($first) {
                for ($i = 0; $i < $count; $i++) {
                    $this->persistZval($table + $i * $zvalSize, $new + $i * $zvalSize);
                }
            }
        }

        $this->persistHashData($ce->constants_table, $ceCopy->constants_table, function (int $zvalSource, int $zvalCopy): void {
            $zv            = Core::pointerAtAddress('zval *', $zvalSource);
            $constSource   = $this->ptrValue($zv->value, 'ptr');
            [$new, $first] = $this->unit($constSource, Core::sizeOfType(zend_class_constant::class));
            if ($this->phase === 2) {
                $this->put(Core::pointerAtAddress('zval *', $zvalCopy)->value, 'ptr', $new);
            }
            if (!$first) {
                return;
            }
            $constant     = Core::pointerAtAddress('zend_class_constant *', $constSource);
            $constantCopy = $this->phase === 2 ? Core::pointerAtAddress('zend_class_constant *', $new) : $constant;
            $this->persistZval($constSource, $this->phase === 2 ? $new : $constSource); // value zval is the first member
            $docComment = $this->ptrValue($constant, 'doc_comment');
            if ($docComment !== 0) {
                $this->put($constantCopy, 'doc_comment', $this->persistString($docComment));
            }
            $this->persistAttributes($constant, $constantCopy, 'attributes');
            $this->defer($constantCopy, 'ce', $this->ptrValue($constant, 'ce'), 'class constant scope');
            $this->persistType($constant->type, $constantCopy->type);
        });

        $filename = $this->ptrValue($ce->info->user, 'filename');
        if ($filename !== 0) {
            $this->put($ceCopy->info->user, 'filename', $this->persistString($filename));
        }
        $docComment = $this->ptrValue($ce, 'doc_comment');
        if ($docComment !== 0) {
            $this->put($ceCopy, 'doc_comment', $this->persistString($docComment));
        }
        $this->persistAttributes($ce, $ceCopy, 'attributes');

        $this->persistHashData($ce->properties_info, $ceCopy->properties_info, function (int $zvalSource, int $zvalCopy): void {
            $zv            = Core::pointerAtAddress('zval *', $zvalSource);
            $propSource    = $this->ptrValue($zv->value, 'ptr');
            [$new, $first] = $this->unit($propSource, Core::sizeOfType(zend_property_info::class));
            if ($this->phase === 2) {
                $this->put(Core::pointerAtAddress('zval *', $zvalCopy)->value, 'ptr', $new);
            }
            if (!$first) {
                return;
            }
            $prop     = Core::pointerAtAddress('zend_property_info *', $propSource);
            $propCopy = $this->phase === 2 ? Core::pointerAtAddress('zend_property_info *', $new) : $prop;
            $this->defer($propCopy, 'ce', $this->ptrValue($prop, 'ce'), 'property scope');
            $this->put($propCopy, 'name', $this->persistString($this->ptrValue($prop, 'name')));
            $docComment = $this->ptrValue($prop, 'doc_comment');
            if ($docComment !== 0) {
                $this->put($propCopy, 'doc_comment', $this->persistString($docComment));
            }
            $this->persistAttributes($prop, $propCopy, 'attributes');
            $prototype = $this->ptrValue($prop, 'prototype');
            if ($prototype !== 0) {
                $this->defer($propCopy, 'prototype', $prototype, 'property prototype');
            }
            $hooks = $this->ptrValue($prop, 'hooks');
            if ($hooks !== 0) {
                [$newHooks, $firstHooks] = $this->unit($hooks, self::PROPERTY_HOOK_COUNT * PHP_INT_SIZE);
                $this->put($propCopy, 'hooks', $newHooks);
                if ($firstHooks) {
                    for ($i = 0; $i < self::PROPERTY_HOOK_COUNT; $i++) {
                        $hookSource = $this->slotValue($hooks + $i * PHP_INT_SIZE);
                        if ($hookSource !== 0) {
                            $this->putAt($newHooks + $i * PHP_INT_SIZE, $this->persistFunction($hookSource));
                        }
                    }
                }
            }
            $this->persistType($prop->type, $propCopy->type);
        });

        $propTable = $this->ptrValue($ce, 'properties_info_table');
        if ($propTable !== 0) {
            [$new, $first] = $this->unit($propTable, $ce->default_properties_count * PHP_INT_SIZE);
            $this->put($ceCopy, 'properties_info_table', $new);
            if ($first) {
                for ($i = 0; $i < $ce->default_properties_count; $i++) {
                    $slotTarget = $this->slotValue($propTable + $i * PHP_INT_SIZE);
                    if ($slotTarget !== 0) {
                        $this->deferAt($new + $i * PHP_INT_SIZE, $slotTarget, 'properties_info_table entry');
                    }
                }
            }
        }

        if ($ce->num_interfaces !== 0) {
            if (($ce->ce_flags & Core::engineConstant('ZEND_ACC_LINKED')) !== 0) {
                // Mirrors the ZEND_ASSERT in zend_file_cache_serialize_class
                throw OpCacheException::unsupportedPayload('a linked class with interfaces cannot be re-serialized');
            }
            $this->persistClassNames($ce, $ceCopy, 'interface_names', $ce->num_interfaces);
        }
        if ($ce->num_traits !== 0) {
            $this->persistClassNames($ce, $ceCopy, 'trait_names', $ce->num_traits);
            $this->persistTraitAliases($ce, $ceCopy);
            $this->persistTraitPrecedences($ce, $ceCopy);
        }

        foreach (self::MAGIC_METHOD_FIELDS as $field) {
            $target = $this->ptrValue($ce, $field);
            if ($target !== 0) {
                $this->defer($ceCopy, $field, $target, "magic method {$field}");
            }
        }

        $iteratorFuncs = $this->ptrValue($ce, 'iterator_funcs_ptr');
        if ($iteratorFuncs !== 0) {
            [$new, $first] = $this->unit($iteratorFuncs, Core::sizeOfType(zend_class_iterator_funcs::class));
            $this->put($ceCopy, 'iterator_funcs_ptr', $new);
            if ($first) {
                $funcs     = Core::pointerAtAddress('zend_class_iterator_funcs *', $iteratorFuncs);
                $funcsCopy = $this->phase === 2 ? Core::pointerAtAddress('zend_class_iterator_funcs *', $new) : $funcs;
                foreach (self::ITERATOR_FUNC_FIELDS as $field) {
                    $target = $this->ptrValue($funcs, $field);
                    if ($target !== 0) {
                        $this->defer($funcsCopy, $field, $target, "iterator {$field}");
                    }
                }
            }
        }
        $arrayAccessFuncs = $this->ptrValue($ce, 'arrayaccess_funcs_ptr');
        if ($arrayAccessFuncs !== 0) {
            [$new, $first] = $this->unit($arrayAccessFuncs, Core::sizeOfType(zend_class_arrayaccess_funcs::class));
            $this->put($ceCopy, 'arrayaccess_funcs_ptr', $new);
            if ($first) {
                $funcs     = Core::pointerAtAddress('zend_class_arrayaccess_funcs *', $arrayAccessFuncs);
                $funcsCopy = $this->phase === 2 ? Core::pointerAtAddress('zend_class_arrayaccess_funcs *', $new) : $funcs;
                foreach (self::ARRAYACCESS_FUNC_FIELDS as $field) {
                    $target = $this->ptrValue($funcs, $field);
                    if ($target !== 0) {
                        $this->defer($funcsCopy, $field, $target, "arrayaccess {$field}");
                    }
                }
            }
        }

        // zend_persist_class_entry: the inheritance cache never survives a persist
        if ($this->phase === 2 && $this->ptrValue($ceCopy, 'inheritance_cache') !== 0) {
            $this->put($ceCopy, 'inheritance_cache', 0);
        }

        return $ceNew;
    }

    /**
     * @param \FFI\CData $ce
     * @param \FFI\CData $ceCopy
     */
    private function persistClassNames(object $ce, object $ceCopy, string $field, int $count): void
    {
        $source = $this->ptrValue($ce, $field);
        if ($source === 0) {
            return;
        }
        $nameSize      = Core::sizeOfType(zend_class_name::class);
        [$new, $first] = $this->unit($source, $count * $nameSize);
        $this->put($ceCopy, $field, $new);
        if (!$first) {
            return;
        }
        for ($i = 0; $i < $count; $i++) {
            $entrySource = Core::pointerAtAddress('zend_class_name *', $source + $i * $nameSize);
            $entryCopy   = $this->phase === 2
                ? Core::pointerAtAddress('zend_class_name *', $new + $i * $nameSize)
                : $entrySource;
            $this->put($entryCopy, 'name', $this->persistString($this->ptrValue($entrySource, 'name')));
            $this->put($entryCopy, 'lc_name', $this->persistString($this->ptrValue($entrySource, 'lc_name')));
        }
    }

    /**
     * @param \FFI\CData $ce
     * @param \FFI\CData $ceCopy
     */
    private function persistTraitAliases(object $ce, object $ceCopy): void
    {
        $source = $this->ptrValue($ce, 'trait_aliases');
        if ($source === 0) {
            return;
        }
        $count = 0;
        while ($this->slotValue($source + $count * PHP_INT_SIZE) !== 0) {
            $count++;
        }
        [$new, $first] = $this->unit($source, ($count + 1) * PHP_INT_SIZE);
        $this->put($ceCopy, 'trait_aliases', $new);
        if (!$first) {
            return;
        }
        for ($i = 0; $i < $count; $i++) {
            $aliasSource             = $this->slotValue($source + $i * PHP_INT_SIZE);
            [$newAlias, $firstAlias] = $this->unit($aliasSource, Core::sizeOfType(zend_trait_alias::class));
            $this->putAt($new + $i * PHP_INT_SIZE, $newAlias);
            if (!$firstAlias) {
                continue;
            }
            $alias     = Core::pointerAtAddress('zend_trait_alias *', $aliasSource);
            $aliasCopy = $this->phase === 2 ? Core::pointerAtAddress('zend_trait_alias *', $newAlias) : $alias;
            foreach (['method_name', 'class_name'] as $nameField) {
                $address = $this->ptrValue($alias->trait_method, $nameField);
                if ($address !== 0) {
                    $this->put($aliasCopy->trait_method, $nameField, $this->persistString($address));
                }
            }
            $address = $this->ptrValue($alias, 'alias');
            if ($address !== 0) {
                $this->put($aliasCopy, 'alias', $this->persistString($address));
            }
        }
    }

    /**
     * @param \FFI\CData $ce
     * @param \FFI\CData $ceCopy
     */
    private function persistTraitPrecedences(object $ce, object $ceCopy): void
    {
        $source = $this->ptrValue($ce, 'trait_precedences');
        if ($source === 0) {
            return;
        }
        $count = 0;
        while ($this->slotValue($source + $count * PHP_INT_SIZE) !== 0) {
            $count++;
        }
        [$new, $first] = $this->unit($source, ($count + 1) * PHP_INT_SIZE);
        $this->put($ceCopy, 'trait_precedences', $new);
        if (!$first) {
            return;
        }
        for ($i = 0; $i < $count; $i++) {
            $precedenceSource = $this->slotValue($source + $i * PHP_INT_SIZE);
            $precedence       = Core::pointerAtAddress('zend_trait_precedence *', $precedenceSource);
            $size             = Core::sizeOfType(zend_trait_precedence::class)
                + PHP_INT_SIZE * ($precedence->num_excludes - 1);
            [$newPrecedence, $firstPrecedence] = $this->unit($precedenceSource, $size);
            $this->putAt($new + $i * PHP_INT_SIZE, $newPrecedence);
            if (!$firstPrecedence) {
                continue;
            }
            $precedenceCopy = $this->phase === 2
                ? Core::pointerAtAddress('zend_trait_precedence *', $newPrecedence)
                : $precedence;
            foreach (['method_name', 'class_name'] as $nameField) {
                $address = $this->ptrValue($precedence->trait_method, $nameField);
                if ($address !== 0) {
                    $this->put($precedenceCopy->trait_method, $nameField, $this->persistString($address));
                }
            }
            $excludeBase     = Core::addressOf($precedence->exclude_class_names);
            $excludeCopyBase = $this->phase === 2 ? Core::addressOf($precedenceCopy->exclude_class_names) : $excludeBase;
            for ($j = 0; $j < $precedence->num_excludes; $j++) {
                $address = $this->slotValue($excludeBase + $j * PHP_INT_SIZE);
                if ($address !== 0) {
                    $this->putAt($excludeCopyBase + $j * PHP_INT_SIZE, $this->persistString($address));
                }
            }
        }
    }

    // --- the script root -----------------------------------------------------------------

    private function persistScript(int $source): void
    {
        [$new, ]    = $this->unit($source, Core::sizeOfType(zend_persistent_script::class));
        $script     = Core::pointerAtAddress('zend_persistent_script *', $source);
        $scriptCopy = $this->phase === 2 ? Core::pointerAtAddress('zend_persistent_script *', $new) : $script;

        $this->put($scriptCopy->script, 'filename', $this->persistString($this->ptrValue($script->script, 'filename')));

        $this->persistHashData($script->script->class_table, $scriptCopy->script->class_table, function (int $zvalSource, int $zvalCopy): void {
            $zv  = Core::pointerAtAddress('zval *', $zvalSource);
            $new = $this->persistClassEntry($this->ptrValue($zv->value, 'ce'));
            if ($this->phase === 2) {
                $this->put(Core::pointerAtAddress('zval *', $zvalCopy)->value, 'ce', $new);
            }
        });
        $this->persistHashData($script->script->function_table, $scriptCopy->script->function_table, function (int $zvalSource, int $zvalCopy): void {
            $zv  = Core::pointerAtAddress('zval *', $zvalSource);
            $new = $this->persistFunction($this->ptrValue($zv->value, 'func'));
            if ($this->phase === 2) {
                $this->put(Core::pointerAtAddress('zval *', $zvalCopy)->value, 'func', $new);
            }
        });

        $mainSource = Core::addressOf(Core::addr($script->script->main_op_array));
        $mainCopy   = $this->phase === 2 ? Core::addressOf(Core::addr($scriptCopy->script->main_op_array)) : $mainSource;
        $this->persistOpArrayBody($mainSource, $mainCopy);

        $warnings = $this->ptrValue($script, 'warnings');
        if ($warnings !== 0) {
            [$new, $first] = $this->unit($warnings, $script->num_warnings * PHP_INT_SIZE);
            $this->put($scriptCopy, 'warnings', $new);
            if ($first) {
                for ($i = 0; $i < $script->num_warnings; $i++) {
                    $warningSource               = $this->slotValue($warnings + $i * PHP_INT_SIZE);
                    [$newWarning, $firstWarning] = $this->unit($warningSource, Core::sizeOfType(zend_error_info::class));
                    $this->putAt($new + $i * PHP_INT_SIZE, $newWarning);
                    if (!$firstWarning) {
                        continue;
                    }
                    $warning     = Core::pointerAtAddress('zend_error_info *', $warningSource);
                    $warningCopy = $this->phase === 2 ? Core::pointerAtAddress('zend_error_info *', $newWarning) : $warning;
                    foreach (['filename', 'message'] as $stringField) {
                        $address = $this->ptrValue($warning, $stringField);
                        if ($address !== 0) {
                            $this->put($warningCopy, $stringField, $this->persistString($address));
                        }
                    }
                }
            }
        }

        $earlyBindings = $this->ptrValue($script, 'early_bindings');
        if ($earlyBindings !== 0) {
            $bindingSize   = Core::sizeOfType(zend_early_binding::class);
            [$new, $first] = $this->unit($earlyBindings, $script->num_early_bindings * $bindingSize);
            $this->put($scriptCopy, 'early_bindings', $new);
            if ($first) {
                for ($i = 0; $i < $script->num_early_bindings; $i++) {
                    $bindingSource = Core::pointerAtAddress('zend_early_binding *', $earlyBindings + $i * $bindingSize);
                    $bindingCopy   = $this->phase === 2
                        ? Core::pointerAtAddress('zend_early_binding *', $new + $i * $bindingSize)
                        : $bindingSource;
                    foreach (['lcname', 'rtd_key', 'lc_parent_name'] as $stringField) {
                        $address = $this->ptrValue($bindingSource, $stringField);
                        if ($address !== 0) {
                            $this->put($bindingCopy, $stringField, $this->persistString($address));
                        }
                    }
                }
            }
        }

        if ($this->phase === 2) {
            // script->size is load-bearing: the loader's IS_SERIALIZED bound.
            // script->mem is rewritten by zend_file_cache_unserialize on load.
            $scriptCopy->size = $this->total;
            if ($this->ptrValue($scriptCopy, 'mem') !== 0) {
                $this->put($scriptCopy, 'mem', 0);
            }
        }
    }
}
