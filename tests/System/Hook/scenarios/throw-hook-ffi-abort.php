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

/*
 * Sacrificial child for ThrowExceptionHookAbortTest: installs a PHP callback into
 * the engine's zend_throw_exception_hook function pointer and throws.
 *
 * zend_throw_exception_internal() sets EG(exception) BEFORE invoking the hook, and
 * ext/ffi refuses to enter a PHP callback while the engine carries a live exception:
 * the trampoline raises the fatal "Throwing from FFI callbacks is not allowed" and
 * the exception semantics of the script are destroyed (the catch block never runs).
 *
 * The binding is done with a plain FFI::cdef against the exported engine symbol on
 * purpose - the pin covers the engine/ext-ffi behavior itself, not z-engine
 * plumbing, which is why z-engine ships no wrapper for this hook (the generated
 * header still exports the symbol for native consumers and future FFI versions).
 * First-chance exception interception from userland goes through
 * OpCode::setHandler(OpCode::THROW, ...) instead, which fires before the throw.
 */
$engine = FFI::cdef('extern void (*zend_throw_exception_hook)(void *exception);');

$engine->zend_throw_exception_hook = static function (mixed $exception): void {
    echo 'HOOK-FIRED', PHP_EOL;
};

try {
    throw new RuntimeException('sacrificial throw');
} catch (RuntimeException $unreachable) {
    echo 'CAUGHT', PHP_EOL;
} finally {
    // Unreached today; restores the pointer if a future ext-ffi lifts the abort
    $engine->zend_throw_exception_hook = null;
}

echo 'SURVIVED', PHP_EOL;
