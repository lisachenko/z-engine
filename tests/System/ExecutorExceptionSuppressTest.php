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

namespace ZEngine\System;

use ArrayObject;
use Closure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZEngine\Core;

/**
 * Executor::suppressCurrentException() coverage.
 *
 * What is testable from userland - and why the "live" clearing path is not:
 *
 *  - The engine never executes PHP code while EG(exception) is set: throwing switches
 *    the frame opline to EG(exception_op) and the VM unwinds before any userland opcode
 *    runs again (see the matching note on Executor::getCurrentException() in #68).
 *  - Planting an exception object into EG(exception) manually (raw FFI write) is not a
 *    usable test setup either: the write itself completes as an ASSIGN_OBJ opcode whose
 *    trailing CHECK_EXCEPTION re-dispatches the saved opline in an infinite loop, because
 *    nothing switched the frame to EG(exception_op) - this was verified empirically while
 *    developing this test. Exercising zend_clear_exception() with a live exception
 *    therefore requires C-level instrumentation (a C shim that sets and clears within one
 *    native call), which z-engine deliberately does not ship.
 *
 * What this test locks down instead:
 *
 *  - the guarded no-op contract in an engine-invoked callback context (a user opcode
 *    handler running from the VM dispatch via the FFI trampoline) - the context in which
 *    a real consumer would call suppressCurrentException();
 *  - the zend_clear_exception symbol itself: linkable and directly callable, returning
 *    immediately when neither EG(exception) nor EG(prev_exception) is set;
 *  - execution state integrity after the calls (the hooked opcode still computes).
 */
#[Group('internal')]
final class ExecutorExceptionSuppressTest extends TestCase
{
    public function testSuppressInsideHookedOpcodeHandlerKeepsExecutionIntact(): void
    {
        $log = new ArrayObject([
            'hasExceptionInHook'        => true,
            'suppressReturned'          => false,
            'hasExceptionAfterSuppress' => true,
        ]);
        $hook = OpCode::setHandler(OpCode::ADD, function ($scope) use ($log): int {
            $log['hasExceptionInHook'] = Core::$executor->hasException();
            Core::$executor->suppressCurrentException();
            $log['suppressReturned']          = true;
            $log['hasExceptionAfterSuppress'] = Core::$executor->hasException();

            return Core::ZEND_USER_OPCODE_DISPATCH;
        });

        try {
            $probe  = self::compileProbe('$a + $b');
            $result = $probe(2, 3);
        } finally {
            $hook->uninstall();
        }

        $this->assertSame(5, $result);
        $this->assertFalse($log['hasExceptionInHook']);
        $this->assertTrue($log['suppressReturned']);
        $this->assertFalse($log['hasExceptionAfterSuppress']);
    }

    public function testDirectClearExceptionCallIsSafeWithoutException(): void
    {
        $this->assertFalse(Core::$executor->hasException());

        // zend_clear_exception() with no prev/current exception returns immediately;
        // this proves the generated symbol resolves and the call marshals correctly
        Core::call('zend_clear_exception');

        $this->assertFalse(Core::$executor->hasException());
        $this->assertNull(Core::$executor->getCurrentException());
    }

    public function testSuppressIsIdempotent(): void
    {
        Core::$executor->suppressCurrentException();
        Core::$executor->suppressCurrentException();

        $this->assertFalse(Core::$executor->hasException());
    }

    private static function compileProbe(string $expression): Closure
    {
        $name = str_replace('.', '_', uniqid('zengine_suppress_probe_', true));
        eval("function {$name}(\$a, \$b) { return {$expression}; }");
        assert(is_callable($name));

        return Closure::fromCallable($name);
    }
}
