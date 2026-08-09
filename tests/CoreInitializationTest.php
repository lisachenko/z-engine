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
}
