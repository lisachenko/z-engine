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

use PHPUnit\Framework\TestCase;

/**
 * Registry semantics that do not require touching the engine module registry (module
 * registration is irreversible per process); the full register/get round trip runs in
 * the request-cycle child process (PersistentHeapRequestCycleTest).
 */
class ExtensionManagerTest extends TestCase
{
    public function testUnregisteredModuleIsAbsent(): void
    {
        $this->assertFalse(ExtensionManager::has(ZEngineModule::class));
    }

    public function testGetOnUnregisteredModuleThrowsInsteadOfBooting(): void
    {
        $this->expectException(ExtensionNotRegisteredException::class);
        $this->expectExceptionMessage('ZEngineModule is not registered');
        ExtensionManager::get(ZEngineModule::class);
    }
}
