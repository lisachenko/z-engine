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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZEngine\Core;

/**
 * Named spin target: the interrupt callback lands on a loop back-edge of whatever
 * frame is running, and a named function keeps that frame reflectable by name
 * (frames of closures cannot be resolved through the native ReflectionFunction
 * constructor, and throwing inside an FFI callback is fatal)
 */
function interruptibleSpinner(): int
{
    $spin = 0;
    for ($index = 0; $index < 100000; $index++) {
        $spin += $index;
        if ($index === 10) {
            Core::$executor->requestInterrupt();
        }
    }

    return $spin;
}

/**
 * Lifecycle of the zend_interrupt_function hook: requestInterrupt() delivery at the
 * next VM interrupt check, frame observation, and clean restore
 */
#[Group('internal')]
final class InterruptHookTest extends TestCase
{
    public function testInterruptFiresAtNextCheckAfterRequest(): void
    {
        $log  = new ArrayObject();
        $hook = Core::setInterruptHandler(function (InterruptHook $hook) use ($log): void {
            $log->append($hook->getExecutionData()->getFunction()->getName());
            // The engine default is NULL but ext/pcntl claims the pointer when
            // loaded: chain to whatever was there so its bookkeeping stays intact
            if ($hook->hasOriginalHandler()) {
                $hook->proceed();
            }
        });

        try {
            $this->assertTrue($hook->isInstalled());

            interruptibleSpinner();

            // Delivered exactly once, inside the interrupted frame: interrupt checks
            // sit on loop back-edges, so the spinner loop is where it lands
            $this->assertSame([__NAMESPACE__ . '\interruptibleSpinner'], $log->getArrayCopy());
        } finally {
            $hook->uninstall();
        }
        $this->assertFalse($hook->isInstalled());
    }

    public function testRequestWithoutInstalledHandlerIsConsumedSilently(): void
    {
        // No handler installed: the engine consumes the flag at the next interrupt
        // check (same path the request timeout uses) and continues normally
        Core::$executor->requestInterrupt();

        $spin = 0;
        for ($index = 0; $index < 100000; $index++) {
            $spin += $index;
        }
        $this->assertGreaterThan(0, $spin);
    }
}
