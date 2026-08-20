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

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZEngine\Core;
use ZEngine\Generated\zend_op;
use ZEngine\Generated\zend_persistent_script;
use ZEngine\System\OpCode;
use ZEngine\Type\OpLine;

/**
 * Tripwire for the relocator's opline-opaque contract (issue #119).
 *
 * PayloadRelocator never rewrites per-opline operands because every 64-bit
 * build compiles with relative addressing (ZEND_USE_ABS_CONST_ADDR /
 * ZEND_USE_ABS_JMP_ADDR are 1 only when SIZEOF_SIZE_T == 4, zend_compile.h) -
 * darwin x64/arm64 included, they are not special. In such payloads IS_CONST
 * operands are literal-table indexes and jump operands are opline-relative
 * byte offsets, both position-independent. This test proves that on a real
 * compiled payload and FAILS - it does not skip - if a supported build ever
 * produced absolute (buffer-offset) operands, which would need the
 * per-opline walk of zend_file_cache.c that only 32-bit builds compile in.
 */
#[Group('opcache')]
#[Group('opcache-relocator')]
final class OpcodeAddressingModelTest extends TestCase
{
    use FileCacheFixture;

    /** Opcodes whose op1/op2 is a jump target in zend_file_cache.c's ABS-addr switch (CATCH/FE_FETCH/SWITCH use extended_value and are not probed) */
    private const array OP1_JUMPS = [OpCode::JMP];
    private const array OP2_JUMPS = [
        OpCode::JMPZ, OpCode::JMPNZ, OpCode::JMPZ_EX, OpCode::JMPNZ_EX,
        OpCode::JMP_SET, OpCode::COALESCE, OpCode::FE_RESET_R, OpCode::FE_RESET_RW,
        OpCode::ASSERT_CHECK, OpCode::JMP_NULL, OpCode::BIND_INIT_STATIC_OR_JMP,
        OpCode::JMP_FRAMELESS,
    ];

    protected function setUp(): void
    {
        if (!PayloadRelocator::isSupported()) {
            self::markTestSkipped(
                'The file-cache relocator supports 64-bit POSIX payloads only'
                . ' (Windows opcache support is an intentional non-goal, issue #119)',
            );
        }
    }

    protected function tearDown(): void
    {
        self::removeCacheDir();
    }

    public function testPayloadOplinesUseRelativeAddressing(): void
    {
        $binPath = self::compileFixture(self::probeFixturePath());
        $payload = substr((string) file_get_contents($binPath), CacheMetaInfo::byteSize());
        $meta    = CacheMetaInfo::parse((string) file_get_contents($binPath), $binPath);

        $length = strlen($payload);
        $buffer = Core::new("char[{$length}]", false);
        Core::memcpy($buffer, $payload, $length);
        $relocator = new PayloadRelocator($buffer, $meta);
        $relocator->relocate();

        $base        = Core::addressOf(Core::addr($buffer));
        $script      = Core::pointerAtAddress(zend_persistent_script::class, $base + $meta->scriptOffset());
        $mainOpArray = $script->script->main_op_array;
        $lastLiteral = $mainOpArray->last_literal;
        $lastOpline  = $mainOpArray->last;
        $opSize      = Core::sizeOfType(zend_op::class);
        $opcodes     = $mainOpArray->opcodes;
        self::assertNotNull($opcodes);
        $opcodesAddr  = Core::addressOf($opcodes);
        $opcodesBytes = $lastOpline * $opSize;

        $constOperands = 0;
        $jumpOperands  = 0;
        for ($i = 0; $i < $lastOpline; $i++) {
            $opline = Core::pointerAtAddress(zend_op::class, $opcodesAddr + $i * $opSize);
            foreach ([
                'op1' => [$opline->op1_type, $opline->op1->constant],
                'op2' => [$opline->op2_type, $opline->op2->constant],
            ] as $node => [$type, $index]) {
                if ($type !== OpLine::IS_CONST) {
                    continue;
                }
                $constOperands++;
                self::assertLessThan(
                    $lastLiteral,
                    $index,
                    "Opline #{$i} {$node} IS_CONST operand is not a literal-table index - "
                    . 'this build stores absolute constant addresses (ZEND_USE_ABS_CONST_ADDR), '
                    . 'which the relocator does not walk (issue #119)',
                );
            }
            $rawOffset = null;
            $jumpNode  = '';
            if (\in_array($opline->opcode, self::OP1_JUMPS, true)) {
                [$rawOffset, $jumpNode] = [$opline->op1->jmp_offset, 'op1'];
            } elseif (\in_array($opline->opcode, self::OP2_JUMPS, true)) {
                [$rawOffset, $jumpNode] = [$opline->op2->jmp_offset, 'op2'];
            }
            if ($rawOffset !== null) {
                $jumpOperands++;
                $unpacked = unpack('l', pack('V', $rawOffset));
                self::assertIsArray($unpacked);
                $signedOffset = $unpacked[1];
                self::assertIsInt($signedOffset);
                $targetPosition = $i * $opSize + $signedOffset;
                self::assertTrue(
                    $targetPosition >= 0 && $targetPosition < $opcodesBytes && $targetPosition % $opSize === 0,
                    "Opline #{$i} {$jumpNode} jump does not land on an opline of this op_array - "
                    . 'this build stores absolute jump addresses (ZEND_USE_ABS_JMP_ADDR), '
                    . 'which the relocator does not walk (issue #119)',
                );
            }
        }

        self::assertGreaterThan(0, $constOperands, 'The probe fixture must yield at least one IS_CONST operand');
        self::assertGreaterThan(0, $jumpOperands, 'The probe fixture must yield at least one conditional jump');

        // And the invariant holds through the writer: untouched round trip stays exact
        self::assertSame($payload, $relocator->derelocate());
    }

    private static function probeFixturePath(): string
    {
        $path = realpath(__DIR__ . '/fixtures/addressing-probe.php');
        self::assertIsString($path);

        return $path;
    }
}
