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

namespace ZEngine\Hook;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ZEngine\ClassExtension\Hook\InterfaceGetsImplementedHook;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Stub\TestInterface;

/**
 * Lifecycle of engine hooks: idempotent install, guarded uninstall and Core::shutdown
 */
final class HookLifecycleTest extends TestCase
{
    public function testInstallIsIdempotentAndRegisters(): void
    {
        $refInterface = new ReflectionClass(TestInterface::class);
        $hook         = $refInterface->setInterfaceGetsImplementedHandler(
            fn(InterfaceGetsImplementedHook $hook) => Core::SUCCESS,
        );

        try {
            $this->assertTrue($hook->isInstalled());
            $this->assertTrue(Core::isTopHook($hook));

            // A second install must be a no-op, not a self-referencing chain entry
            $hook->install();
            $this->assertTrue($hook->isInstalled());

            $hook->uninstall();
            $this->assertFalse($hook->isInstalled());
        } finally {
            $hook->uninstall();
        }
    }

    public function testUninstallRestoresOriginalBehaviour(): void
    {
        $log          = '';
        $refInterface = new ReflectionClass(TestInterface::class);
        $hook         = $refInterface->setInterfaceGetsImplementedHandler(
            function (InterfaceGetsImplementedHook $hook) use (&$log) {
                $log = $hook->getClass()->getName();

                return Core::SUCCESS;
            },
        );

        $first = new class implements TestInterface {};
        $this->assertStringContainsString('@anonymous', $log);

        $hook->uninstall();
        $log    = '';
        $second = new class implements TestInterface {};
        // The hook writes $log by reference from an engine callback, which no analyser can see
        // @phpstan-ignore method.alreadyNarrowedType (the point of the test is that nothing wrote to it)
        $this->assertSame('', $log);
    }

    public function testOutOfOrderUninstallThrows(): void
    {
        $refInterface = new ReflectionClass(TestInterface::class);
        $firstHook    = $refInterface->setInterfaceGetsImplementedHandler(
            fn(InterfaceGetsImplementedHook $hook) => Core::SUCCESS,
        );
        $secondHook = $refInterface->setInterfaceGetsImplementedHandler(
            fn(InterfaceGetsImplementedHook $hook) => Core::SUCCESS,
        );

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
            // Unwinding in reverse order is legal and restores the original pointer
            $secondHook->uninstall();
            $firstHook->uninstall();
        }
    }

    public function testReinstallKeepsHookActive(): void
    {
        $refInterface = new ReflectionClass(TestInterface::class);
        $hook         = $refInterface->setInterfaceGetsImplementedHandler(
            fn(InterfaceGetsImplementedHook $hook) => Core::SUCCESS,
        );

        try {
            $hook->reinstall();
            $this->assertTrue($hook->isInstalled());
            $this->assertTrue(Core::isTopHook($hook));
        } finally {
            $hook->uninstall();
        }
    }

    #[Group('internal')]
    #[RunInSeparateProcess]
    public function testShutdownUninstallsEverythingAndBlocksNewInstalls(): void
    {
        $refInterface = new ReflectionClass(TestInterface::class);
        $hook         = $refInterface->setInterfaceGetsImplementedHandler(
            fn(InterfaceGetsImplementedHook $hook) => Core::SUCCESS,
        );
        $this->assertTrue($hook->isInstalled());

        Core::shutdown();

        $this->assertTrue(Core::isShutdown());
        $this->assertFalse($hook->isInstalled());

        // Idempotent second call
        Core::shutdown();

        // Installing after shutdown is forbidden: engine writes are no longer safe
        $this->expectException(\LogicException::class);
        $refInterface->setInterfaceGetsImplementedHandler(
            fn(InterfaceGetsImplementedHook $hook) => Core::SUCCESS,
        );
    }
}
