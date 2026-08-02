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
use FFI\CData;
use PHPUnit\Framework\TestCase;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\System\ExecutionData;

/**
 * Guards and callback containment of the observer bridge that are verifiable without a startup-time
 * observer provider (i.e. on a stock build where the engine fcall-observer machinery is disabled).
 *
 * The end-to-end firing path requires the engine observer machinery to be enabled at startup, which
 * userland/FFI cannot arrange; the preload-timing and observed/unobserved boundary are covered by
 * ObserverHookPreloadTest, and the memory-safety reasoning is documented in docs/observer-hook.md.
 */
final class ObserverHookTest extends TestCase
{
    /**
     * The test bootstrap boots through Core::init(), not Core::preload(), so observer registration
     * timing is unavailable: install() must refuse with the typed exception, never silently no-op.
     */
    public function testInstallOutsidePreloadIsRejected(): void
    {
        $function = (new ReflectionFunction('strlen'))->getRawFunctionPointer();
        $hook     = new ObserverHook($function, static function (): void {}, static function (): void {});

        $this->assertFalse(Core::isPreloaded(), 'Test bootstrap must not run through the preload path');

        $this->expectException(ObserverException::class);
        $this->expectExceptionMessage('opcache.preload');
        $hook->install();
    }

    public function testConvenienceEntryPointRejectsOutsidePreload(): void
    {
        $function = (new ReflectionFunction('strlen'))->getRawFunctionPointer();

        $this->expectException(ObserverException::class);
        Core::observeFunction($function, static function (): void {}, static function (): void {});
    }

    public function testBeginCallbackExceptionIsContained(): void
    {
        $hook = new ObserverHook(
            (new ReflectionFunction('strlen'))->getRawFunctionPointer(),
            static function (): void {
                throw new \RuntimeException('boom in begin');
            },
            static function (): void {},
        );

        $warning = $this->captureWarning(static fn() => $hook->handleBegin(self::fakeExecuteData()));

        $this->assertStringContainsString('Observer begin callback threw', $warning);
        $this->assertStringContainsString('boom in begin', $warning);
    }

    public function testEndCallbackExceptionIsContained(): void
    {
        $hook = new ObserverHook(
            (new ReflectionFunction('strlen'))->getRawFunctionPointer(),
            static function (): void {},
            static function (): void {
                throw new \RuntimeException('boom in end');
            },
        );

        // A null return-value pointer is tolerated (generators / abrupt returns pass NULL)
        $warning = $this->captureWarning(static fn() => $hook->handleEnd(self::fakeExecuteData(), null));

        $this->assertStringContainsString('Observer end callback threw', $warning);
        $this->assertStringContainsString('boom in end', $warning);
    }

    public function testBeginCallbackReceivesExecutionData(): void
    {
        $seen = new ArrayObject();
        $hook = new ObserverHook(
            (new ReflectionFunction('strlen'))->getRawFunctionPointer(),
            static function ($frame) use ($seen): void {
                $seen->append($frame instanceof ExecutionData ? 'execution-data' : 'other');
            },
            static function (): void {},
        );

        $hook->handleBegin(self::fakeExecuteData());

        $this->assertSame(['execution-data'], $seen->getArrayCopy());
    }

    public function testHandleIsNotTheDispatchEntryPoint(): void
    {
        $hook = new ObserverHook(
            (new ReflectionFunction('strlen'))->getRawFunctionPointer(),
            static function (): void {},
            static function (): void {},
        );

        $this->assertFalse($hook->isInstalled());
        $this->assertFalse($hook->hasOriginalHandler());

        $this->expectException(\LogicException::class);
        $hook->handle();
    }

    public function testFieldKeyIsScopedToTheTargetFunction(): void
    {
        $strlen = (new ReflectionFunction('strlen'))->getRawFunctionPointer();
        $strrev = (new ReflectionFunction('strrev'))->getRawFunctionPointer();

        $first  = new ObserverHook($strlen, static function (): void {}, static function (): void {});
        $second = new ObserverHook($strlen, static function (): void {}, static function (): void {});
        $other  = new ObserverHook($strrev, static function (): void {}, static function (): void {});

        $this->assertStringStartsWith('observer-fcall::', $first->getHookFieldKey());
        $this->assertSame($first->getHookFieldKey(), $second->getHookFieldKey());
        $this->assertNotSame($first->getHookFieldKey(), $other->getHookFieldKey());
    }

    /**
     * Runs a callback expected to trigger exactly one E_USER_WARNING and returns its message
     */
    private function captureWarning(callable $callback): string
    {
        $message = '';
        set_error_handler(static function (int $severity, string $text) use (&$message): bool {
            $message = $text;

            return true;
        }, E_USER_WARNING);
        try {
            $callback();
        } finally {
            restore_error_handler();
        }
        $this->assertNotSame('', $message, 'Expected an E_USER_WARNING to be triggered');

        return $message;
    }

    /**
     * A throwaway zend_execute_data pointer; ExecutionData only stores it, so the containment and
     * scope tests never dereference engine memory through it.
     */
    private static function fakeExecuteData(): CData
    {
        $frame = Core::new('zend_execute_data');

        return Core::addr($frame);
    }
}
