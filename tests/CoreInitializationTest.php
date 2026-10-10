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

use PHPUnit\Framework\TestCase;

/**
 * Covers the boot-state introspection of Core: whether the engine bridge is ready.
 *
 * The negative (pre-init) case is not testable here: autoloading boots the bridge and
 * PHPUnit's process isolation re-runs the bootstrap in every child process, so no test
 * ever observes an uninitialized Core.
 */
final class CoreInitializationTest extends TestCase
{
    public function testIsInitializedAfterSuiteBootstrap(): void
    {
        // Both the autoload bootstrap and the suite's own explicit init ran for this process;
        // the automatic path is proven separately, in AutoBootTest's child processes
        $this->assertTrue(Core::isInitialized(), 'the suite bootstrap initialized the engine bridge');
    }

    public function testInitIsReInvocableWhenAlreadyInitialized(): void
    {
        // init() deliberately supports repeated calls (reuses the process-wide
        // FFI binding, issue #108); the flag must survive a re-init
        Core::init();
        $this->assertTrue(Core::isInitialized());
    }

    public function testPreloadIsIdempotentAfterTheAutomaticBoot(): void
    {
        // An existing opcache.preload script calls Core::preload() right after requiring the
        // autoloader, which has already published the definitions. The second call has to be
        // a no-op rather than a second FFI::load().
        Core::preload();
        $this->assertTrue(Core::isInitialized());
    }

    public function testEngineHeaderPathIsTheBundledHeaderOfTheRunningBuild(): void
    {
        $header = Core::engineHeaderPath();

        $this->assertFileExists($header);
        $this->assertStringEndsWith(
            str_replace('/', DIRECTORY_SEPARATOR, 'include/' . Core::platformKey() . '/engine.h'),
            $header,
        );
    }

    public function testPreloadScriptPathIsTheBundledScript(): void
    {
        $this->assertSame(realpath(dirname(__DIR__) . '/preload.php'), Core::preloadScriptPath());
    }

    /**
     * The hint is what Core::init() throws under ffi.enable=preload in a web SAPI: it has to
     * carry the resolved paths, so the operator can paste it into php.ini as is
     */
    public function testPreloadConfigurationHintCarriesTheResolvedPaths(): void
    {
        $hint = Core::preloadConfigurationHint();

        $this->assertStringContainsString('ffi.preload=' . Core::engineHeaderPath(), $hint);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertStringContainsString('ffi.enable=1', $hint);
            $this->assertStringNotContainsString('opcache.preload=', $hint);
        } else {
            $this->assertStringContainsString('opcache.preload=' . Core::preloadScriptPath(), $hint);
            $this->assertStringContainsString('opcache.preload_user', $hint);
        }
    }
}
