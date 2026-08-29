<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Standalone probes for issue #280: user opcode handlers corrupt VM state under
 * PHP 8.6's tail-call VM (macOS arm64). Each mode installs the handler RAW via
 * zend_set_user_opcode_handler - no OpCodeHook, no ExecutionData - so a failure
 * points at the engine boundary itself, not at z-engine's wrapper logic.
 *
 * Run as: php -d ffi.enable=1 -d opcache.jit=off probe.php <mode>
 *
 * Modes, from most inert to most revealing:
 *   noop         EXT_STMT handler whose body is `return 2;` - does the mere
 *                round trip through an FFI-callback PHP closure per statement
 *                corrupt the instrumented payload?
 *   log-const    + one file_put_contents() on a literal path per fire (a real
 *                nested internal call, still no variables touched)
 *   globals      + a $GLOBALS counter (symbol-table access, no closure statics)
 *   use-ref      + a by-ref `use (&$fires)` counter - the shape that fails in
 *                lisachenko/zdebug#24's diagnostics
 *   diag         per-fire dump of the engine invariants: the execute_data
 *                argument vs EG(current_execute_data), vm_stack_top/end, the
 *                frame's prev/func/opline pointers and the current opcode
 *   add-baseline ADD handler with the use-ref counter and NO extended-stmt
 *                compilation - the shape z-engine's own suite already proves
 *
 * Every handler returns 2 (ZEND_USER_OPCODE_DISPATCH) so the payload executes
 * exactly as it would uninstrumented; PAYLOAD TOTAL=414 is the canary.
 * Diagnostics go to /tmp/probe-280.log (literal on purpose: reading any PHP
 * variable inside the handler is part of what is under test).
 */
declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use ZEngine\Core;
use ZEngine\System\Compiler;
use ZEngine\System\OpCode;

const PROBE_LOG = '/tmp/probe-280.log';

$mode = $argv[1] ?? 'noop';
@unlink(PROBE_LOG);

/** Stage markers: stderr is unbuffered, so the last marker brackets a crash */
function stage(string $name): void
{
    fwrite(STDERR, "STAGE {$name}\n");
}

stage('autoload');

Core::init();
stage('init');

$fires = 0;

$handler = match ($mode) {
    'noop', 'install-only' => static function ($executeData): int {
        return 2;
    },
    'log-const' => static function ($executeData): int {
        file_put_contents('/tmp/probe-280.log', '.', FILE_APPEND);

        return 2;
    },
    'globals' => static function ($executeData): int {
        $GLOBALS['probe280Fires'] = ($GLOBALS['probe280Fires'] ?? 0) + 1;

        return 2;
    },
    'use-ref', 'add-baseline' => static function ($executeData) use (&$fires): int {
        $fires++;

        return 2;
    },
    'diag' => static function ($executeData): int {
        try {
            // Core::$engine is core-private; a diagnostic tool may reflect. Resolved
            // per fire on purpose: closure captures are part of what is under test.
            $engine = new \ReflectionProperty(Core::class, 'engine')->getValue();
            \assert($engine instanceof \FFI);
            $eg   = $engine->executor_globals;
            $arg  = Core::addressOf($executeData);
            $ced  = $eg->current_execute_data;
            $prev = $executeData->prev_execute_data;
            $func = $executeData->func;
            $opl  = $executeData->opline;
            // Inside the handler EG(current_execute_data) is the handler closure's own
            // frame; the invariant that must hold is that its prev_execute_data chains
            // back to the interrupted frame (chain=1). vm_stack_top must sit above arg.
            $cedPrev = $ced?->prev_execute_data;
            $line    = sprintf(
                "arg=%x eg_ced=%x ced_prev=%x chain=%d top=%x end=%x prev=%x func=%x opline=%x opcode=%d\n",
                $arg,
                $ced     === null ? 0 : Core::addressOf($ced),
                $cedPrev === null ? 0 : Core::addressOf($cedPrev),
                ($cedPrev !== null && Core::addressOf($cedPrev) === $arg) ? 1 : 0,
                Core::addressOf($eg->vm_stack_top),
                Core::addressOf($eg->vm_stack_end),
                $prev === null ? 0 : Core::addressOf($prev),
                $func === null ? 0 : Core::addressOf($func),
                $opl  === null ? 0 : Core::addressOf($opl),
                $opl  === null ? -1 : $opl->opcode,
            );
            file_put_contents('/tmp/probe-280.log', $line, FILE_APPEND);
        } catch (\Throwable $error) {
            file_put_contents('/tmp/probe-280.log', 'EX: ' . $error->getMessage() . "\n", FILE_APPEND);
        }

        return 2;
    },
    default => throw new InvalidArgumentException("Unknown probe mode: {$mode}"),
};

$opCode = $mode === 'add-baseline' ? OpCode::ADD : OpCode::EXT_STMT;
if ($mode !== 'add-baseline') {
    Core::$compiler->setOptions(Core::$compiler->getOptions() | Compiler::COMPILE_EXTENDED_STMT);
}
stage('options');

$result = Core::call('zend_set_user_opcode_handler', $opCode, $handler);
if ($result === Core::FAILURE) {
    fwrite(STDERR, "Failed to install the user opcode handler\n");
    exit(1);
}
stage('installed');

if ($mode !== 'install-only') {
    require __DIR__ . '/payload.php';
    stage('payload-done');
}

// Restore before engine shutdown: payload op_arrays still carry the opcode
Core::call('zend_set_user_opcode_handler', $opCode, null);
stage('uninstalled');

echo "fires={$fires}\n";
echo 'globals=' . ($GLOBALS['probe280Fires'] ?? 0) . "\n";
if (is_file(PROBE_LOG)) {
    $log   = (string) file_get_contents(PROBE_LOG);
    $lines = $log === '' ? [] : explode("\n", trim($log));
    echo 'log-lines=' . count($lines) . "\n";
    // The full trace is short for this payload; print it whole for CI logs
    echo $log;
}
echo "MODE {$mode} DONE\n";
