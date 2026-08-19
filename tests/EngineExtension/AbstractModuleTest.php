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

namespace ZEngine\EngineExtension;

use FFI\CData;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Stub\ArrayGlobalsModule;
use ZEngine\Stub\GlobalsModule;
use ZEngine\Stub\LifecycleModule;
use ZEngine\Stub\ThrowingLifecycleModule;

/**
 * Module lifecycle callbacks, phpinfo output and dependency wiring (issue #75)
 *
 * Every test registers a module in the engine registry, which cannot be undone within the
 * process - hence one process per test.
 */
#[Group('internal')]
class AbstractModuleTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testLifecycleCallbacksFireOnStartup(): void
    {
        $module = new LifecycleModule();
        $this->assertFalse($module->isModuleRegistered());

        $module->register();
        $this->assertTrue($module->isModuleRegistered());
        // Runtime-built name on purpose: under an opcache-active runner the optimizer
        // folds extension_loaded('<literal>') to constant false at compile time, before
        // the module registers (issue #243)
        $this->assertTrue(extension_loaded($module->getName()));
        $this->assertFalse($module->wasModuleStarted());
        $this->assertSame([], LifecycleModule::$events);

        $module->startup();
        $this->assertTrue($module->wasModuleStarted());
        // MINIT is delivered by the engine through the FFI trampoline, RINIT directly by
        // startup() (dl() parity); request/module shutdown belong to the end of the request
        $this->assertSame(['moduleStartup', 'requestStartup'], LifecycleModule::$events);
    }

    #[RunInSeparateProcess]
    public function testPhpInfoRendersModuleSection(): void
    {
        $module = new LifecycleModule();
        $module->register();
        $module->startup();

        ob_start();
        phpinfo(INFO_MODULES);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('lifecycle', $output);
        $this->assertStringContainsString('Lifecycle support => enabled', $output);
        $this->assertStringContainsString('Module version => 1.0.0', $output);
    }

    #[RunInSeparateProcess]
    public function testDependenciesAreVisibleViaReflection(): void
    {
        $module = new LifecycleModule();
        $module->register();
        $module->startup();

        $dependencies = (new \ReflectionExtension('lifecycle'))->getDependencies();
        $this->assertSame('Required', $dependencies['standard'] ?? null);
        $this->assertSame('Optional', $dependencies['spl'] ?? null);
    }

    #[RunInSeparateProcess]
    public function testCallbackExceptionsAreContainedAsWarnings(): void
    {
        $capturedWarnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$capturedWarnings): bool {
            $capturedWarnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            $module = new ThrowingLifecycleModule();
            $module->register();
            // MINIT throws inside the engine-driven FFI trampoline and RINIT throws in the
            // direct delivery: both must be contained (issue #50), the module still starts
            $module->startup();
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($module->wasModuleStarted());
        $allWarnings = implode("\n", $capturedWarnings);
        $this->assertStringContainsString('module_startup_func failed: MINIT boom', $allWarnings);
        $this->assertStringContainsString('requestStartup failed: RINIT boom', $allWarnings);
    }

    /**
     * getGlobals() must cast the raw globals pointer through the POINTER type - size-safe
     * for every globals type, unlike the former value-type cast (issue #109: "attempt to
     * cast to larger type")
     */
    #[RunInSeparateProcess]
    public function testGetGlobalsReturnsTypedPointerForZvalGlobals(): void
    {
        $module = new GlobalsModule();
        $module->register();
        $module->startup();

        $globals = $module->getGlobals();
        $this->assertNotNull($globals);
        $slotType = $globals->u1;
        $this->assertInstanceOf(CData::class, $slotType);
        if (!\ZEND_THREAD_SAFE) {
            // The NTS globals block is zero-initialized at registration (IS_UNDEF): the
            // contract the zengine heap anchor relies on
            $this->assertSame(ReflectionValue::IS_UNDEF, $slotType->type_info);
        }

        // Write through the typed pointer and read the value back through a fresh view
        $slotType->type_info = ReflectionValue::IS_TRUE;
        $freshView           = $module->getGlobals();
        $this->assertNotNull($freshView);
        $freshSlotType = $freshView->u1;
        $this->assertInstanceOf(CData::class, $freshSlotType);
        $this->assertSame(ReflectionValue::IS_TRUE, $freshSlotType->type_info);
    }

    /**
     * Array-typed globals (the README counter example) decay to an element pointer,
     * exactly like a C array expression, so indexing keeps working (issue #109)
     */
    #[RunInSeparateProcess]
    public function testGetGlobalsDecaysArrayTypedGlobalsToElementPointer(): void
    {
        $module = new ArrayGlobalsModule();
        $module->register();
        $module->startup();

        $globals = $module->getGlobals();
        $this->assertNotNull($globals);
        $globals[3] = 42;
        $freshView  = $module->getGlobals();
        $this->assertNotNull($freshView);
        $this->assertSame(42, $freshView[3]);
    }

    #[RunInSeparateProcess]
    public function testInvalidDependencyDeclarationsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDependency::required('standard', 'unknown-relation', '1.0');
    }

    /**
     * End-to-end shutdown ordering, observed from a child process:
     *
     *  1. moduleStartup/requestStartup during startup (before any shutdown)
     *  2. Core::shutdown() (registered first) restores the engine pointers
     *  3. requestShutdown() is delivered right after it (Core::isShutdown() is true)
     *  4. later user shutdown functions run after the module delivery
     *  5. moduleShutdown() is never delivered: real MSHUTDOWN runs after FFI teardown
     *  6. the process exits cleanly through the engine's temporary-module destruction
     */
    #[RunInSeparateProcess]
    public function testRequestShutdownIsDeliveredAfterCoreShutdownAtRequestEnd(): void
    {
        $command = [
            PHP_BINARY,
            '-d', 'ffi.enable=1',
            '-d', 'zend.assertions=1',
            '-d', 'assert.exception=1',
            '-d', 'report_memleaks=1',
            '-d', 'display_errors=on',
            '-d', 'error_reporting=-1',
            __DIR__ . '/fixture/module-lifecycle-order.php',
        ];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process, 'Unable to spawn the fixture child process');

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report = "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";
        $this->assertSame(0, $exitCode, "Fixture exited with code {$exitCode}\n{$report}");

        $expectedSequence = [
            'moduleStartup(coreShutdown=false)',
            'requestStartup(coreShutdown=false)',
            'conflict-rejected',
            'script-end',
            'requestShutdown(coreShutdown=true)',
            'late-user-shutdown(coreShutdown=true)',
        ];
        $offset = 0;
        foreach ($expectedSequence as $marker) {
            $position = strpos($stdout, $marker, $offset);
            $this->assertNotFalse($position, "Marker '{$marker}' missing or out of order\n{$report}");
            $offset = $position + strlen($marker);
        }
        // Real MSHUTDOWN runs after the FFI bridge teardown: never delivered
        $this->assertStringNotContainsString('moduleShutdown', $stdout, $report);
    }
}
