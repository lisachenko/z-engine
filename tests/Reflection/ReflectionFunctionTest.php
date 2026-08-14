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

namespace ZEngine\Reflection;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Test function to reflect
 */
function testFunction(): ?string
{
    return 'Test';
}

class ReflectionFunctionTest extends TestCase
{
    private ReflectionFunction $refFunction;

    protected function setUp(): void
    {
        $this->refFunction = new ReflectionFunction(__NAMESPACE__ . '\\' . 'testFunction');
    }

    public function testSetDeprecated(): void
    {
        $this->markTestSkipped('User function does not trigger deprecation error');
    }

    public function testSetInternalFunctionDeprecated(): void
    {
        $refFunction = new ReflectionFunction('var_dump');
        try {
            $refFunction->setDeprecated();
            $this->assertTrue($refFunction->isDeprecated());

            // Marking an internal function deprecated must make the engine emit
            // an E_DEPRECATED on the next call - capture it directly rather than
            // relying on PHPUnit's removed Error\Deprecated bridge.
            $captured = null;
            set_error_handler(static function (int $level, string $message) use (&$captured): bool {
                $captured = $message;

                return true;
            }, E_DEPRECATED);
            // Buffer the dump output: the deprecation still fires, but the print
            // would otherwise mark the test risky under beStrictAboutOutputDuringTests
            ob_start();
            try {
                var_dump(42);
            } finally {
                ob_end_clean();
                restore_error_handler();
            }

            $this->assertNotNull($captured, 'Expected an E_DEPRECATED notice for the deprecated function');
            $this->assertMatchesRegularExpression('/Function var_dump\(\) is deprecated/', $captured);
        } finally {
            $refFunction->setDeprecated(false);
        }
    }

    #[Group('internal')]
    public function testRedefineThrowsAnExceptionForIncompatibleCallback(): void
    {
        $this->expectException(\ReflectionException::class);
        $expectedRegexp = '/"function \(\)" should be compatible with original "function \(\)\: \?string"/';
        $this->expectExceptionMessageMatches($expectedRegexp);

        $this->refFunction->redefine(function () {
            echo 'Nope';
        });
    }

    #[Group('internal')]
    public function testRedefine(): void
    {
        // The previous function body is destroyed by the swap (issue #64): the debug
        // leak gate now enforces that no residual is left behind
        $this->refFunction->redefine(function (): ?string {
            return 'Yes';
        });
        // Check that all main info were preserved
        $this->assertFalse($this->refFunction->isClosure());
        $this->assertSame('testFunction', $this->refFunction->getShortName());

        $result = testFunction();

        // Our function now returns Yes instead of Test
        $this->assertSame('Yes', $result);
    }

    #[Group('internal')]
    public function testRedefineInternalFunc(): void
    {
        $originalValue = zend_version();
        $refFunction   = new ReflectionFunction('zend_version');

        $refFunction->redefine(function (): string {
            return 'Z-Engine';
        });

        $modifiedValue = zend_version();
        $this->assertNotSame($originalValue, $modifiedValue);
        $this->assertSame('Z-Engine', $modifiedValue);
    }

    #[Group('internal')]
    public function testAddFunction(): void
    {
        // Immortal-by-design: addFunction() keeps the closure body alive until the end of the
        // request (the published zend_function lives inside the closure object, see
        // docs/memory-model.md), so the debug-build shutdown report would flag it although
        // nothing is wrong
        @ini_set('report_memleaks', '0'); // deprecated in PHP 8.5, still the only leak-report switch

        $functionName = 'zengine_generated_twice';
        $refFunction  = ReflectionFunction::addFunction($functionName, fn(int $x): int => $x * 2);

        $this->assertTrue(function_exists($functionName));
        $this->assertSame($functionName, $refFunction->getName());
        $this->assertFalse($refFunction->isClosure());

        // The generated function dispatches through the normal VM with no FFI trampoline
        // @phpstan-ignore callable.nonCallable (function is generated at runtime by addFunction)
        $this->assertSame(42, $functionName(21));

        // The returned reflection is fully functional, including the pointer-level API: the
        // redefining closure must stay alive while the function is callable (it owns the
        // op_array the function now executes)
        $newBody = fn(int $x): int => $x * 3;
        $refFunction->redefine($newBody);
        // @phpstan-ignore callable.nonCallable (function is generated at runtime by addFunction)
        $this->assertSame(63, $functionName(21));
    }

    public function testAddFunctionRejectsExistingName(): void
    {
        $this->expectException(\ReflectionException::class);
        $this->expectExceptionMessageMatches('/already exists in the engine/');

        // strlen is always registered - addFunction must refuse to clobber it (and bail out
        // before touching any engine memory)
        ReflectionFunction::addFunction('strlen', fn(): int => 0);
    }
}
