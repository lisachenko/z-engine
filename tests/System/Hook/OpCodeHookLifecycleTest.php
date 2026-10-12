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

use PHPUnit\Framework\TestCase;
use ZEngine\Core;
use ZEngine\System\OpCode;
use ZEngine\System\VmKind;

/**
 * The user opcode hook install/uninstall lifecycle, exercised in every CI leg
 *
 * Deliberately NOT in the `internal` group: the full OpCodeHookTest is internal-only, so
 * this is the one hook test release builds run on every platform. The handler is never
 * dispatched (no probe code is compiled while it is installed), which keeps the happy
 * path safe for release builds.
 */
final class OpCodeHookLifecycleTest extends TestCase
{
    public function testVmKindIsReported(): void
    {
        $this->assertContains(Core::getVirtualMachineKind(), VmKind::cases(), 'zend_vm_kind() must answer one of the known VM kinds');
    }

    public function testInstallAndUninstallLifecycle(): void
    {
        $hook = OpCode::setHandler(OpCode::EXT_STMT, static fn($scope): int => Core::ZEND_USER_OPCODE_DISPATCH);
        try {
            $this->assertTrue($hook->isInstalled());
        } finally {
            $hook->uninstall();
        }
        $this->assertFalse($hook->isInstalled());
    }
}
