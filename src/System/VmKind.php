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

namespace ZEngine\System;

/**
 * Named view of the executor's dispatch strategy, as reported by zend_vm_kind()
 *
 * The backing values are the ZEND_VM_KIND_* macro values from Zend/zend_vm_opcodes.h: a
 * build-time choice baked into the running binary, so the whole process always reports a
 * single case. They are preprocessor macros the header generator does not export, which is
 * why the decode lives here rather than behind Core::engineConstant().
 *
 * @see Core::getVirtualMachineKind()
 */
enum VmKind: int
{
    /** ZEND_VM_KIND_CALL: every opcode handler is a plain function called through a dispatch table */
    case Call = 1;

    /** ZEND_VM_KIND_SWITCH: one giant switch over the opcode number inside execute_ex() */
    case Switch = 2;

    /** ZEND_VM_KIND_GOTO: computed-goto dispatch between handler labels (GCC builds) */
    case Goto = 3;

    /** ZEND_VM_KIND_HYBRID: computed-goto fast path with callable handlers kept for the slow path */
    case Hybrid = 4;

    /** ZEND_VM_KIND_TAILCALL: clang musttail/preserve_none handler chains (new in PHP 8.6) */
    case TailCall = 5;
}
