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

/**
 * The user opcode hook install/uninstall lifecycle, exercised in every CI leg
 *
 * Deliberately NOT in the `internal` group: the full OpCodeHookTest is internal-only, so
 * this is the one hook test release builds run on every platform (that gap is how the
 * PHP 8.6 tail-call VM breakage of issue #280 went unnoticed - fixed upstream by
 * php/php-src#24109). The handler is never dispatched (no probe code is compiled while
 * it is installed), which keeps the happy path safe for release builds.
 */
final class OpCodeHookLifecycleTest extends TestCase
{
    public function testVmKindIsReported(): void
    {
        $kind = Core::vmKind();

        $this->assertContains($kind, [
            Core::VM_KIND_CALL,
            Core::VM_KIND_SWITCH,
            Core::VM_KIND_GOTO,
            Core::VM_KIND_HYBRID,
            Core::VM_KIND_TAILCALL,
        ], 'zend_vm_kind() must answer one of the known VM kinds');
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
