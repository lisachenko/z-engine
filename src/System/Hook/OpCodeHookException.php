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

/**
 * Raised when the engine refuses to publish or restore a user opcode handler through
 * zend_set_user_opcode_handler(): the handler table rejected the write, so the hook chain
 * is left exactly as it was - install() did not take effect, or uninstall() could not put
 * the captured predecessor back.
 *
 * Extends \RuntimeException, exactly what the inline throws it replaces threw.
 */
final class OpCodeHookException extends \RuntimeException
{
    public static function handlerInstallFailed(): self
    {
        return new self('Can not install user opcode handler');
    }

    public static function handlerRestoreFailed(): self
    {
        return new self('Can not restore original opcode handler');
    }

    public static function tailCallVmUnsupported(): self
    {
        return new self(
            'User opcode handlers are unsupported on this PHP build: its tail-call VM '
            . '(ZEND_VM_KIND_TAILCALL, e.g. clang builds on Apple Silicon since PHP 8.6) '
            . 'resumes execution against a stale frame after a user opcode handler fires, '
            . 'corrupting the process. Use a hybrid/call-VM PHP build instead. '
            . 'See https://github.com/lisachenko/z-engine/issues/280',
        );
    }
}
