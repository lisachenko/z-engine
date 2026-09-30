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
 * The tail-call VM guard for issue #280
 *
 * PHP 8.6's ZEND_VM_KIND_TAILCALL (clang builds without global-register support, e.g.
 * Apple Silicon) resumes execution against a stale execute_ex frame after a user opcode
 * handler fires, so OpCodeHook::install() must refuse there instead of corrupting the
 * process. On every other VM kind the install/uninstall lifecycle must be untouched.
 *
 * Deliberately NOT in the `internal` group: this guard is exactly what protects release
 * builds, so it runs in every CI leg - including macOS arm64, the only runner where the
 * tail-call branch is actually taken. The handler is never dispatched (no probe code is
 * compiled while it is installed), which keeps the happy path safe for release builds.
 */
final class OpCodeHookVmKindGuardTest extends TestCase
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

    public function testInstallRefusesOnTheTailCallVmAndWorksElsewhere(): void
    {
        if (Core::vmKind() === Core::VM_KIND_TAILCALL) {
            $this->expectException(OpCodeHookException::class);
            $this->expectExceptionMessageMatches('/tail-call VM/');
            OpCode::setHandler(OpCode::EXT_STMT, static fn($scope): int => Core::ZEND_USER_OPCODE_DISPATCH);

            return;
        }

        $hook = OpCode::setHandler(OpCode::EXT_STMT, static fn($scope): int => Core::ZEND_USER_OPCODE_DISPATCH);
        try {
            $this->assertTrue($hook->isInstalled());
        } finally {
            $hook->uninstall();
        }
        $this->assertFalse($hook->isInstalled());
    }
}
