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

namespace ZEngine\System\Hook;

use ArrayObject;
use Closure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ZEngine\Core;
use ZEngine\System\ExecutionData;
use ZEngine\System\OpCode;

/**
 * Lifecycle of user opcode handlers: install, chaining, guarded uninstall and Core::shutdown
 */
#[Group('internal')]
final class OpCodeHookTest extends TestCase
{
    public function testHandlerFiresAndCanDispatch(): void
    {
        $log  = new ArrayObject();
        $hook = OpCode::setHandler(OpCode::ADD, function ($scope) use ($log): int {
            $log->append($scope instanceof ExecutionData ? 'fired' : 'wrong-scope');

            return Core::ZEND_USER_OPCODE_DISPATCH;
        });

        try {
            $this->assertInstanceOf(OpCodeHook::class, $hook);
            $this->assertTrue($hook->isInstalled());
            $this->assertTrue(Core::isTopHook($hook));
            $this->assertSame(OpCode::ADD, $hook->getOpCode());
            $this->assertFalse($hook->hasOriginalHandler());

            $probe = self::compileProbe('$a + $b');
            $this->assertSame(5, $probe(2, 3));
            $this->assertSame(['fired'], $log->getArrayCopy());
        } finally {
            $hook->uninstall();
        }
        $this->assertFalse($hook->isInstalled());
    }

    public function testInstallIsIdempotentAndReinstallKeepsHookActive(): void
    {
        $hook = OpCode::setHandler(OpCode::ADD, fn($scope): int => Core::ZEND_USER_OPCODE_DISPATCH);

        try {
            // A second install must be a no-op, not a self-referencing chain entry
            $hook->install();
            $this->assertTrue($hook->isInstalled());
            $this->assertTrue(Core::isTopHook($hook));

            $hook->reinstall();
            $this->assertTrue($hook->isInstalled());
            $this->assertTrue(Core::isTopHook($hook));
        } finally {
            $hook->uninstall();
        }
    }

    public function testSecondHandlerChainsOnDispatch(): void
    {
        $log       = new ArrayObject();
        $firstHook = OpCode::setHandler(OpCode::ADD, function ($scope) use ($log): int {
            $log->append('first');

            return Core::ZEND_USER_OPCODE_DISPATCH;
        });
        $secondHook = OpCode::setHandler(OpCode::ADD, function ($scope) use ($log): int {
            $log->append('second');

            return Core::ZEND_USER_OPCODE_DISPATCH;
        });

        try {
            $this->assertFalse($firstHook->hasOriginalHandler());
            $this->assertTrue($secondHook->hasOriginalHandler());
            $this->assertTrue(Core::isTopHook($secondHook));

            $probe = self::compileProbe('$a + $b');
            $this->assertSame(30, $probe(10, 20));
            // Top handler fires first, its dispatch reaches the previously installed one
            $this->assertSame(['second', 'first'], $log->getArrayCopy());
        } finally {
            $secondHook->uninstall();
            $firstHook->uninstall();
        }
    }

    public function testUninstallTopReactivatesPreviousHandler(): void
    {
        $log       = new ArrayObject();
        $firstHook = OpCode::setHandler(OpCode::ADD, function ($scope) use ($log): int {
            $log->append('first');

            return Core::ZEND_USER_OPCODE_DISPATCH;
        });
        $secondHook = OpCode::setHandler(OpCode::ADD, function ($scope) use ($log): int {
            $log->append('second');

            return Core::ZEND_USER_OPCODE_DISPATCH;
        });

        try {
            $secondHook->uninstall();
            $this->assertFalse($secondHook->isInstalled());
            $this->assertTrue(Core::isTopHook($firstHook));

            $probe = self::compileProbe('$a + $b');
            $this->assertSame(15, $probe(7, 8));
            $this->assertSame(['first'], $log->getArrayCopy());
        } finally {
            $secondHook->uninstall();
            $firstHook->uninstall();
        }

        // With every hook uninstalled a freshly compiled probe runs without interception
        $log->exchangeArray([]);
        $probe = self::compileProbe('$a + $b');
        $this->assertSame(2, $probe(1, 1));
        $this->assertSame([], $log->getArrayCopy());
    }

    public function testOutOfOrderUninstallThrows(): void
    {
        $firstHook  = OpCode::setHandler(OpCode::ADD, fn($scope): int => Core::ZEND_USER_OPCODE_DISPATCH);
        $secondHook = OpCode::setHandler(OpCode::ADD, fn($scope): int => Core::ZEND_USER_OPCODE_DISPATCH);

        try {
            $this->assertTrue(Core::isTopHook($secondHook));
            $this->assertFalse(Core::isTopHook($firstHook));

            $caught = null;
            try {
                $firstHook->uninstall();
            } catch (\LogicException $exception) {
                $caught = $exception;
            }
            $this->assertInstanceOf(\LogicException::class, $caught);
            $this->assertTrue($firstHook->isInstalled());
        } finally {
            // Unwinding in reverse order is legal and restores the previous handlers
            $secondHook->uninstall();
            $firstHook->uninstall();
        }
    }

    public function testIndependentOpCodesUnwindIndependently(): void
    {
        $log     = new ArrayObject();
        $addHook = OpCode::setHandler(OpCode::ADD, function ($scope) use ($log): int {
            $log->append('add');

            return Core::ZEND_USER_OPCODE_DISPATCH;
        });
        $subHook = OpCode::setHandler(OpCode::SUB, function ($scope) use ($log): int {
            $log->append('sub');

            return Core::ZEND_USER_OPCODE_DISPATCH;
        });

        try {
            $probe = self::compileProbe('($a + $a) - $b');
            $this->assertSame(7, $probe(5, 3));
            $this->assertSame(['add', 'sub'], $log->getArrayCopy());

            // Uninstalling the SUB hook must not disturb the ADD chain
            OpCode::restoreHandler(OpCode::SUB);
            $this->assertFalse($subHook->isInstalled());
            $this->assertTrue($addHook->isInstalled());

            $log->exchangeArray([]);
            $probe = self::compileProbe('($a + $a) - $b');
            $this->assertSame(7, $probe(5, 3));
            $this->assertSame(['add'], $log->getArrayCopy());

            OpCode::restoreHandler(OpCode::ADD);
            $this->assertFalse($addHook->isInstalled());
            // Restoring an opcode without any installed hook is an idempotent no-op
            OpCode::restoreHandler(OpCode::ADD);

            $log->exchangeArray([]);
            $probe = self::compileProbe('($a + $a) - $b');
            $this->assertSame(7, $probe(5, 3));
            $this->assertSame([], $log->getArrayCopy());
        } finally {
            $subHook->uninstall();
            $addHook->uninstall();
        }
    }

    public function testInvalidHandlerSignatureIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Opcode handler signature should be: function($scope): int {}');

        OpCode::setHandler(OpCode::ADD, function () {});
    }

    #[RunInSeparateProcess]
    public function testShutdownUninstallsOpCodeHooksAndBlocksNewInstalls(): void
    {
        $hook = OpCode::setHandler(OpCode::ADD, fn($scope): int => Core::ZEND_USER_OPCODE_DISPATCH);
        $this->assertTrue($hook->isInstalled());

        Core::shutdown();

        $this->assertTrue(Core::isShutdown());
        $this->assertFalse($hook->isInstalled());

        // Idempotent second call
        Core::shutdown();

        // Installing after shutdown is forbidden: engine writes are no longer safe
        $this->expectException(\LogicException::class);
        OpCode::setHandler(OpCode::ADD, fn($scope): int => Core::ZEND_USER_OPCODE_DISPATCH);
    }

    /**
     * Compiles a fresh two-argument probe function and returns it as a closure
     *
     * User opcode handlers only affect op_arrays compiled AFTER installation, so every
     * probe must be compiled once the handler configuration under test is in place. The
     * probe is a global function (not a method of this ZEngine\* test class) because
     * opcodes executed inside ZEngine classes bypass user handlers by design. Every probe
     * gets a unique name and is never called again after its handler chain changed:
     * an op_array compiled against a user opcode keeps dispatching through the user
     * handler table for its whole lifetime.
     */
    private static function compileProbe(string $expression): Closure
    {
        $name = str_replace('.', '_', uniqid('zengine_opcode_probe_', true));
        eval("function {$name}(\$a, \$b) { return {$expression}; }");
        assert(is_callable($name));

        return Closure::fromCallable($name);
    }
}
