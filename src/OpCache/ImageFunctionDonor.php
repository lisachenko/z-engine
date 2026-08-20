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

use FFI\CData;
use ZEngine\Core;
use ZEngine\Generated\zend_function;
use ZEngine\Generated\zend_op;
use ZEngine\Generated\zend_op_array;
use ZEngine\Generated\znode_op;
use ZEngine\Generated\zval;
use ZEngine\Reflection\FunctionLikeInterface;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\System\OpCode;
use ZEngine\Type\HashTable;
use ZEngine\Type\OpLine;
use ZEngine\Type\StructArray;

/**
 * Turns one function body of a relocated cache image into an executable donor
 * for the in-place body-swap machinery (FunctionBodySwap), and provides the
 * body-equality basis the cache-image bridge diffs with.
 *
 * A relocated image (PayloadRelocator) is structurally walkable but NOT
 * executable: the relocator deliberately preserves the two execution-only
 * encodings opcache stores in the file byte-for-byte, because the engine
 * re-derives them when IT loads the binary (zend_file_cache_unserialize):
 *
 *  - every opline's handler is the serialized INDEX into the VM handler table
 *    (zend_serialize_opcode_handler), not a callable handler pointer;
 *  - every IS_CONST operand is the literal's INDEX into op_array->literals,
 *    not the runtime relative-offset form RT_CONSTANT() dereferences on this
 *    platform (!ZEND_USE_ABS_CONST_ADDR).
 *
 * materialize() performs exactly the engine's own normalization, on a copy:
 * the opcode array and the literal table are copied side by side into one
 * process-owned block (the runtime constant form is a signed 32-bit offset
 * from the opline, so both arrays must stay within one allocation, mirroring
 * the engine's own co-allocation), IS_CONST operands are rewritten to that
 * form and the handlers are restored through the engine's own
 * zend_deserialize_opcode_handler(). The image buffer itself is never written,
 * so PayloadRelocator::derelocate() (BinaryCacheFile::save()) keeps producing
 * a valid binary after donors were materialized from the image.
 *
 * Everything else in the donor body - literal payloads (strings, immutable
 * arrays), CV name table, arg_info, static-variable defaults - keeps pointing
 * INTO the image buffer, exactly like the engine executes a file_cache_only
 * script straight out of its load buffer. Two lifetime rules follow
 * (docs/long-running.md):
 *
 *  - the image buffer and the materialized block must outlive every entry the
 *    donor body was swapped into; both are request-lifetime allocations that
 *    are never explicitly freed, and this object pins the CData handles;
 *  - the donor op_array carries NO refcount (opcache persisted it that way),
 *    so the engine never destroys the swapped-in body: destroy_op_array
 *    releases only the entry's name and heap run-time cache, the same
 *    contract shared-memory bodies follow.
 *
 * @internal core-layer machinery of the cache-image bridge (CacheImageSync)
 */
final class ImageFunctionDonor
{
    /**
     * fn_flags that describe how a body is STORED, not what it does: they legitimately
     * differ between a serialized image body and the live entry compiled from the same
     * source, so the equality basis masks them out. IMMUTABLE marks opcache-shared
     * storage; HEAP_RT_CACHE marks a per-entry heap run-time cache (set by every swap).
     */
    private const int STORAGE_ONLY_FLAGS = Core::ZEND_ACC_IMMUTABLE | Core::ZEND_ACC_HEAP_RT_CACHE;

    /**
     * Opcodes whose op1 operand is the object RECEIVER: an IS_UNUSED op1 there means
     * the implicit $this, and the compiler copies an UNINITIALIZED znode into the
     * operand (zend_compile.c: zend_delayed_compile_prop() and the method-call path
     * only set obj_node.op_type when this_guaranteed_exists()), so op1.num holds
     * nondeterministic stack garbage. The VM never reads it for these opcodes, and the
     * equality basis must ignore it - it differs between any two compilations.
     *
     * Deliberately UNTYPED (no `const array`): a typed array constant whose value is a
     * constant expression trips the debug-build engine assertion
     * `zend_update_class_constant: !EG(exception)` when this library is preloaded
     * (opcache.preload evaluates the AST during preload linking) - the untyped form,
     * like ClassDelta::MAGIC_METHOD_NAMES, preloads cleanly on release and debug alike.
     *
     * @var list<int>
     */
    private const THIS_RECEIVER_OPCODES = [
        OpCode::ASSIGN_OBJ,
        OpCode::ASSIGN_OBJ_OP,
        OpCode::ASSIGN_OBJ_REF,
        OpCode::UNSET_OBJ,
        OpCode::FETCH_OBJ_R,
        OpCode::FETCH_OBJ_W,
        OpCode::FETCH_OBJ_RW,
        OpCode::FETCH_OBJ_IS,
        OpCode::FETCH_OBJ_FUNC_ARG,
        OpCode::FETCH_OBJ_UNSET,
        OpCode::INIT_METHOD_CALL,
        OpCode::PRE_INC_OBJ,
        OpCode::PRE_DEC_OBJ,
        OpCode::POST_INC_OBJ,
        OpCode::POST_DEC_OBJ,
        OpCode::ISSET_ISEMPTY_PROP_OBJ,
    ];

    /**
     * @param ReflectionFunction   $donorFunction Pointer-level view of the donor container
     * @param CData|zend_function  $container     zend_function the swap machinery copies from
     * @param CData                $bodyBlock     [opcodes][literals] block the donor points into
     */
    private function __construct(
        private readonly ReflectionFunction $donorFunction,
        // @phpstan-ignore property.onlyWritten (pure lifetime retention until the swap commits)
        private readonly object $container,
        // @phpstan-ignore property.onlyWritten (pure lifetime retention: the swapped-in body executes out of it)
        private readonly object $bodyBlock,
    ) {}

    /**
     * Materializes an executable donor from an image function (see class docblock)
     *
     * The returned object OWNS the donor: it must stay referenced at least until the
     * body swap consuming the donor has committed (the zend_function container bytes
     * are copied into the live entry by the swap), and the swapped-in body keeps
     * executing out of the pinned block and the image buffer afterwards.
     *
     * @param FunctionLikeInterface $imageFunction User function of a relocated image
     */
    public static function materialize(FunctionLikeInterface $imageFunction): self
    {
        $imageOpArray = $imageFunction->getOpArrayPointer();
        $opSize       = Core::sizeOfType(zend_op::class);
        $zvalSize     = Core::sizeOfType(zval::class);
        $opcodesBytes = $imageOpArray->last         * $opSize;
        $literalBytes = $imageOpArray->last_literal * $zvalSize;

        // One co-allocated [opcodes][literals] block: the runtime IS_CONST form is a
        // signed 32-bit opline-relative offset, so the literal table must live next to
        // the opcodes (the engine co-allocates them for the same reason). The block is
        // request memory that is never explicitly freed - the refcount-less body stays
        // published until request end, where table teardown provably never reads it
        // (destroy_op_array returns before touching opcodes of a refcount-less body).
        assert($imageOpArray->opcodes !== null && $opcodesBytes > 0);
        $bodyBlock    = Core::new('char[' . ($opcodesBytes + $literalBytes) . ']', false);
        $blockAddress = Core::addressOf(Core::addr($bodyBlock));
        $literalsBase = $blockAddress + $opcodesBytes;
        Core::memcpy($bodyBlock, $imageOpArray->opcodes, $opcodesBytes);
        if ($literalBytes > 0) {
            assert($imageOpArray->literals !== null);
            $literalsTarget = Core::pointerAtAddress('char *', $literalsBase);
            Core::memcpy($literalsTarget, $imageOpArray->literals, $literalBytes);
        }

        for ($index = 0; $index < $imageOpArray->last; $index++) {
            $oplineAddress = $blockAddress + $index * $opSize;
            /** @var zend_op $opline */
            $opline = Core::pointerAtAddress('zend_op *', $oplineAddress);
            if ($opline->op1_type === OpLine::IS_CONST) {
                $opline->op1->constant = ($literalsBase + $opline->op1->constant * $zvalSize) - $oplineAddress;
            }
            if ($opline->op2_type === OpLine::IS_CONST) {
                $opline->op2->constant = ($literalsBase + $opline->op2->constant * $zvalSize) - $oplineAddress;
            }
            // The engine's own index -> handler-pointer restoration (zend_vm.h), the
            // exact call zend_file_cache_unserialize_op_array performs per opline
            Core::call('zend_deserialize_opcode_handler', $opline);
        }

        // The donor container is a writable copy of the image zend_function: the image
        // struct itself is never written, so save()/derelocate() stays valid. The swap
        // machinery copies the container bytes into the live entry wholesale.
        $container = Core::new(zend_function::class);
        Core::memcpy($container, $imageFunction->getEntryPointer(), Core::sizeOfType(zend_function::class));
        $donorFunction = ReflectionFunction::fromCData(Core::cast('zend_function *', Core::addr($container)));
        $donorOpArray  = $donorFunction->getOpArrayPointer();
        /** @var zend_op $blockOpcodes Narrowed at the boundary: the block starts with the opcode array */
        $blockOpcodes          = Core::pointerAtAddress('zend_op *', $blockAddress);
        $donorOpArray->opcodes = $blockOpcodes;
        if ($literalBytes > 0) {
            /** @var zval $blockLiterals Narrowed at the boundary: literals follow the opcodes */
            $blockLiterals          = Core::pointerAtAddress('zval *', $literalsBase);
            $donorOpArray->literals = $blockLiterals;
        }
        // The donor is a per-process body, not opcache-shared storage: without this the
        // swapped-in entry would advertise ZEND_ACC_IMMUTABLE semantics (map-ptr offset
        // statics slot, refusal of in-place mutation) it does not actually have
        $donorFunction->getCommonPointer()->fn_flags &= ~Core::ZEND_ACC_IMMUTABLE;

        return new self($donorFunction, $container, $bodyBlock);
    }

    /**
     * The materialized donor, ready for FunctionBodySwap::swapUserFunctionBody()
     */
    public function getDonor(): ReflectionFunction
    {
        return $this->donorFunction;
    }

    /**
     * Compares an image function body against a live entry's compiled body
     *
     * The equality basis is: the body metrics (opcode/literal/CV/temporary counts,
     * argument counts), the fn_flags word without the storage-only bits, the CV name
     * table, every opline in canonicalized form (IS_CONST operands compared by literal
     * INDEX - derived from the runtime relative-offset form on the live side - and the
     * handler ignored, since it is storage-form-specific), every literal by value
     * (ReflectionValue::equals) and the static-variable defaults table by value.
     *
     * The comparison is deliberately conservative where value equality cannot be
     * proven cheaply: array and constant-expression literals (and static defaults)
     * always count as different, exactly like ReflectionMethod::equals(). A body that
     * carries them is re-applied by every sync even when it did not change - a safe
     * false POSITIVE. Known false NEGATIVES are declaration-surface-only edits the
     * bridge does not model: arg_info type/name changes and doc comments do not enter
     * the comparison (see docs/opcache-binary.md).
     *
     * @param FunctionLikeInterface $imageFunction User function of a relocated image (serialized opline form)
     * @param FunctionLikeInterface $liveFunction  Live user function published in an executor table (runtime form)
     */
    public static function bodiesEqual(FunctionLikeInterface $imageFunction, FunctionLikeInterface $liveFunction): bool
    {
        $imageOpArray = $imageFunction->getOpArrayPointer();
        $liveOpArray  = $liveFunction->getOpArrayPointer();

        $metricsAgree = $imageOpArray->last                          === $liveOpArray->last
            && $imageOpArray->last_var                               === $liveOpArray->last_var
            && $imageOpArray->last_literal                           === $liveOpArray->last_literal
            && $imageOpArray->T                                      === $liveOpArray->T
            && $imageOpArray->num_args                               === $liveOpArray->num_args
            && $imageOpArray->required_num_args                      === $liveOpArray->required_num_args
            && ($imageOpArray->fn_flags & ~self::STORAGE_ONLY_FLAGS) === ($liveOpArray->fn_flags & ~self::STORAGE_ONLY_FLAGS);
        if (!$metricsAgree) {
            return false;
        }
        if ($imageFunction->getVariableNames() !== $liveFunction->getVariableNames()) {
            return false;
        }

        return self::opcodesEqual($imageOpArray, $liveOpArray)
            && self::literalsEqual($imageOpArray, $liveOpArray)
            && self::staticDefaultsEqual($imageOpArray, $liveOpArray);
    }

    /**
     * Opline-by-opline comparison across the two storage forms (equal counts assumed)
     *
     * @param zend_op_array $imageOpArray Serialized form: IS_CONST operands hold literal indexes
     * @param zend_op_array $liveOpArray  Runtime form: IS_CONST operands hold opline-relative offsets
     */
    private static function opcodesEqual(object $imageOpArray, object $liveOpArray): bool
    {
        $opSize   = Core::sizeOfType(zend_op::class);
        $zvalSize = Core::sizeOfType(zval::class);
        assert($imageOpArray->opcodes !== null && $liveOpArray->opcodes !== null);
        $imageBase           = Core::addressOf($imageOpArray->opcodes);
        $liveBase            = Core::addressOf($liveOpArray->opcodes);
        $liveLiteralsAddress = $liveOpArray->literals !== null ? Core::addressOf($liveOpArray->literals) : 0;

        for ($index = 0; $index < $imageOpArray->last; $index++) {
            /** @var zend_op $imageOpline */
            $imageOpline = Core::pointerAtAddress('zend_op *', $imageBase + $index * $opSize);
            /** @var zend_op $liveOpline */
            $liveOpline = Core::pointerAtAddress('zend_op *', $liveBase + $index * $opSize);

            $shapeAgrees = $imageOpline->opcode === $liveOpline->opcode
                && $imageOpline->op1_type       === $liveOpline->op1_type
                && $imageOpline->op2_type       === $liveOpline->op2_type
                && $imageOpline->result_type    === $liveOpline->result_type
                && $imageOpline->extended_value === $liveOpline->extended_value
                && $imageOpline->lineno         === $liveOpline->lineno
                && $imageOpline->result->num    === $liveOpline->result->num;
            if (!$shapeAgrees) {
                return false;
            }

            $liveOplineAddress = $liveBase + $index * $opSize;
            $skipReceiverNoise = $imageOpline->op1_type === OpLine::IS_UNUSED
                && in_array($imageOpline->opcode, self::THIS_RECEIVER_OPCODES, true);
            if (!$skipReceiverNoise) {
                $op1Agrees = self::operandsEqual(
                    $imageOpline->op1_type,
                    $imageOpline->op1,
                    $liveOpline->op1,
                    $liveOplineAddress,
                    $liveLiteralsAddress,
                    $zvalSize,
                );
                if (!$op1Agrees) {
                    return false;
                }
            }
            $op2Agrees = self::operandsEqual(
                $imageOpline->op2_type,
                $imageOpline->op2,
                $liveOpline->op2,
                $liveOplineAddress,
                $liveLiteralsAddress,
                $zvalSize,
            );
            if (!$op2Agrees) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compares one operand across the two storage forms
     *
     * @param znode_op $imageOperand Serialized form: an IS_CONST operand holds the literal index
     * @param znode_op $liveOperand  Runtime form: an IS_CONST operand holds the opline-relative offset
     */
    private static function operandsEqual(
        int $operandType,
        object $imageOperand,
        object $liveOperand,
        int $liveOplineAddress,
        int $liveLiteralsAddress,
        int $zvalSize,
    ): bool {
        if ($operandType === OpLine::IS_CONST) {
            // Canonical form is the literal index: the live side stores the
            // opline-relative byte offset of the literal (RT_CONSTANT form)
            $liveOffset = self::toSignedInt32($liveOperand->constant);
            $liveIndex  = intdiv($liveOplineAddress + $liveOffset - $liveLiteralsAddress, $zvalSize);

            return $imageOperand->constant === $liveIndex;
        }

        return $imageOperand->num === $liveOperand->num;
    }

    /**
     * Literal-table comparison by value (equal literal counts assumed)
     *
     * @param zend_op_array $imageOpArray
     * @param zend_op_array $liveOpArray
     */
    private static function literalsEqual(object $imageOpArray, object $liveOpArray): bool
    {
        $totalLiterals = $imageOpArray->last_literal;
        if ($totalLiterals === 0) {
            return true;
        }
        assert($imageOpArray->literals !== null && $liveOpArray->literals !== null);
        $imageLiterals = new StructArray($imageOpArray->literals, $totalLiterals);
        $liveLiterals  = new StructArray($liveOpArray->literals, $totalLiterals);
        for ($index = 0; $index < $totalLiterals; $index++) {
            $imageValue = ReflectionValue::fromValueEntry($imageLiterals[$index]);
            $liveValue  = ReflectionValue::fromValueEntry($liveLiterals[$index]);
            if (!$imageValue->equals($liveValue)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Static-variable DEFAULTS comparison by value (the declaration table, never the
     * live per-process table a call may have materialized)
     *
     * @param zend_op_array $imageOpArray
     * @param zend_op_array $liveOpArray
     */
    private static function staticDefaultsEqual(object $imageOpArray, object $liveOpArray): bool
    {
        $imageDefaults = $imageOpArray->static_variables;
        $liveDefaults  = $liveOpArray->static_variables;
        if (($imageDefaults === null) !== ($liveDefaults === null)) {
            return false;
        }
        if ($imageDefaults === null || $liveDefaults === null) {
            return true;
        }
        $imageTable = HashTable::fromCData($imageDefaults);
        $liveTable  = HashTable::fromCData($liveDefaults);
        if (count($imageTable) !== count($liveTable)) {
            return false;
        }
        foreach ($imageTable as $variableName => $imageValue) {
            $liveValue = $liveTable->find((string) $variableName);
            if ($liveValue === null || !$imageValue->equals($liveValue)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reinterprets a uint32 field value as the signed 32-bit offset it stores
     */
    private static function toSignedInt32(int $value): int
    {
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }
}
