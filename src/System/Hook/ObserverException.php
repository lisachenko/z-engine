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

use RuntimeException;

/**
 * Raised when an ObserverHook cannot be installed on the current engine state.
 *
 * The zend_observer fcall machinery has hard timing and memory-safety
 * preconditions that a userland/FFI consumer cannot satisfy on every build.
 * Rather than silently doing nothing (a call that never fires) or writing into
 * structures the engine sized without an observer slot (memory corruption),
 * ObserverHook refuses with this typed exception. See docs/observer-hook.md for
 * the full boundary description.
 */
final class ObserverException extends RuntimeException
{
    /**
     * Observer registration was attempted outside the opcache.preload boot path
     */
    public static function notPreloaded(): self
    {
        return new self(
            'ObserverHook can only be installed from the opcache.preload script (Core::preload()). '
            . 'The engine freezes its observer configuration during startup, before a normal '
            . 'Core::init() request begins, so a non-preload setup cannot attach fcall observers. '
            . 'See docs/observer-hook.md.',
        );
    }

    /**
     * A z-engine observer hook is already attached to the target function
     */
    public static function alreadyObserved(): self
    {
        return new self(
            'An ObserverHook is already attached to this function. The engine reserves exactly one '
            . 'begin/end handler pair per registered observer, and z-engine cannot prove a second '
            . 'pair would fit - uninstall the existing hook first.',
        );
    }

    /**
     * The engine's fcall-observer machinery is not enabled on this build
     */
    public static function observersDisabled(): self
    {
        return new self(
            'The engine fcall-observer machinery is disabled (ZEND_OBSERVER_ENABLED is false: '
            . 'zend_observer_fcall_op_array_extension == -1). z-engine cannot enable it from userland '
            . 'because zend_observer_post_startup() has already frozen it by the time the preload script '
            . 'runs, and forcing it on corrupts every op_array compiled without an observer slot. '
            . 'A startup-time (MINIT) observer provider must enable observers first. See docs/observer-hook.md.',
        );
    }
}
