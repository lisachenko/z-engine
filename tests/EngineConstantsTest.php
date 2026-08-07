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

namespace ZEngine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZEngine\AbstractSyntaxTree\NodeKind;
use ZEngine\ClassExtension\Hook\CastType;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\System\OpCode;

/**
 * Asserts that the hand-declared PHP class constants (consumer API) match the
 * values extracted from the engine sources by the generator. Any drift here is
 * a bug that would otherwise silently mis-flag classes, opcodes or AST nodes.
 */
final class EngineConstantsTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function constantOwnerProvider(): array
    {
        return [
            'ZEND_ACC_* on Core'                   => [Core::class, 'ZEND_ACC_'],
            'ZEND_PROPERTY_HOOK_* on Core'         => [Core::class, 'ZEND_PROPERTY_HOOK_'],
            'opcodes on OpCode'                    => [OpCode::class, ''],
            'AST kinds on NodeKind'                => [NodeKind::class, 'AST_'],
            'zval type ids on ReflectionValue'     => [ReflectionValue::class, 'IS_'],
            'cast/internal ids on ReflectionValue' => [ReflectionValue::class, '_IS_'],
        ];
    }

    #[DataProvider('constantOwnerProvider')]
    public function testDeclaredConstantsMatchGeneratedGroundTruth(string $class, string $phpPrefix): void
    {
        $generated  = self::loadGeneratedConstants();
        $reflection = new \ReflectionClass($class);

        $checked = 0;
        foreach ($reflection->getConstants() as $name => $value) {
            if ($phpPrefix !== '' && !str_starts_with($name, $phpPrefix)) {
                continue;
            }
            // Map the PHP constant name back to the engine symbol
            $engineName = match ($class) {
                OpCode::class   => 'ZEND_' . $name,
                NodeKind::class => 'ZEND_' . $name,
                default         => $name,
            };
            if (!array_key_exists($engineName, $generated)) {
                continue;
            }
            $this->assertSame(
                $generated[$engineName],
                $value,
                "{$class}::{$name} ({$value}) does not match engine {$engineName} ({$generated[$engineName]})",
            );
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, "No constants of {$class} were cross-checked against the ground truth");
    }

    public function testModuleApiVersionMatchesRunningEngine(): void
    {
        $this->assertSame(
            self::loadGeneratedConstants()['ZEND_MODULE_API_NO'],
            Core::engineConstant('ZEND_MODULE_API_NO'),
        );
    }

    public function testCastTypeCasesMatchGeneratedGroundTruth(): void
    {
        $generated    = self::loadGeneratedConstants();
        $symbolByCase = [
            'Long'   => 'IS_LONG',
            'Double' => 'IS_DOUBLE',
            'String' => 'IS_STRING',
            'Array'  => 'IS_ARRAY',
            'Object' => 'IS_OBJECT',
            'Bool'   => '_IS_BOOL',
            'Number' => '_IS_NUMBER',
        ];

        foreach (CastType::cases() as $case) {
            $this->assertArrayHasKey($case->name, $symbolByCase, "CastType::{$case->name} has no engine symbol mapping in this test");
            $engineName = $symbolByCase[$case->name];
            $this->assertSame(
                $generated[$engineName],
                $case->value,
                "CastType::{$case->name} ({$case->value}) does not match engine {$engineName} ({$generated[$engineName]})",
            );
        }
    }

    /**
     * @return array<string, int>
     */
    private static function loadGeneratedConstants(): array
    {
        $arch        = php_uname('m');
        $platformKey = sprintf(
            '%d.%d/%s-%s-%s',
            PHP_MAJOR_VERSION,
            PHP_MINOR_VERSION,
            strtolower(PHP_OS_FAMILY),
            match ($arch) {
                'x86_64', 'amd64'  => 'x64',
                'aarch64', 'arm64' => 'arm64',
                default            => $arch,
            },
            ZEND_THREAD_SAFE ? 'zts' : 'nts',
        );

        return require __DIR__ . '/../include/' . $platformKey . '/constants.php';
    }
}
