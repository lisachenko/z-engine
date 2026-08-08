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
 * The negative (pre-init) case is not testable here: the suite bootstrap calls
 * Core::init(), and PHPUnit's process isolation re-runs that bootstrap in every
 * child process, so no test ever observes an uninitialized Core.
 */
final class CoreInitializationTest extends TestCase
{
    public function testIsInitializedAfterSuiteBootstrap(): void
    {
        // tests/bootstrap.php called Core::init() for this process
        $this->assertTrue(Core::isInitialized(), 'the suite bootstrap initialized the engine bridge');
    }

    public function testInitIsReInvocableWhenAlreadyInitialized(): void
    {
        // init() deliberately supports repeated calls (reuses the process-wide
        // FFI binding, issue #108); the flag must survive a re-init
        Core::init();
        $this->assertTrue(Core::isInitialized());
    }
}
