<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Self-contained php-src repro for issue #280 - NO z-engine involved, only ext-ffi
 * against the engine's own exported API. Run with:
 *
 *   php -d ffi.enable=1 -d opcache.jit=off pure-ffi-repro.php
 *
 * On hybrid/call-VM builds it prints int(3) and OK. On a ZEND_VM_KIND_TAILCALL
 * build (clang without global-register support, e.g. macOS arm64 since PHP 8.6)
 * it corrupts execution at the first ADD dispatched inside the function frame:
 * ZEND_USER_OPCODE_SPEC_TAILCALL_HANDLER's DISPATCH case returns the single-step
 * handler's next opline up the musttail chain, and execute_ex() resumes it
 * against the stale frame it was entered with.
 */
declare(strict_types=1);

$engine = FFI::cdef('
    typedef int (*user_opcode_handler_t)(void *execute_data);
    int zend_vm_kind(void);
    int zend_set_user_opcode_handler(unsigned char opcode, user_opcode_handler_t handler);
');

echo 'vm_kind=', $engine->zend_vm_kind(), " (5 = ZEND_VM_KIND_TAILCALL)\n";

// ZEND_ADD = 1; ZEND_USER_OPCODE_DISPATCH = 2 ("call original opcode handler")
$handler = static function ($executeData): int {
    return 2;
};
if ($engine->zend_set_user_opcode_handler(1, $handler) !== 0) {
    fwrite(STDERR, "install failed\n");
    exit(1);
}

// Compiled AFTER the handler is installed, so its ADD dispatches through it.
// The ADD fires inside the function frame - deeper than the frame execute_ex()
// was entered with, which is what the tail-call VM mis-resumes.
$payload = tempnam(sys_get_temp_dir(), 'p280') . '.php';
file_put_contents($payload, <<<'PHP'
    <?php
    function probe280Add(int $a, int $b): int
    {
        return $a + $b;
    }
    var_dump(probe280Add(1, 2));
    PHP);
require $payload;
unlink($payload);

$engine->zend_set_user_opcode_handler(1, null);
echo "OK\n";
