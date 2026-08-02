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

/**
 * opcache.preload fixture for ObserverHookFiringTest.
 *
 * Runs with the observer_enabler test extension loaded (the minimal startup-time observer
 * provider), so the engine fcall-observer machinery is ENABLED and the runtime per-function
 * observer API is live. Exercises the full ObserverHook firing path and records every event into
 * the file named by the ZOBS_OUT environment variable (a plain file: stream resources opened
 * during preload break preload finalization).
 *
 * Scenario selection via ZOBS_SCENARIO:
 *   - "firing" (default): begin/end + return values, uninstall, nested ordering,
 *     begin-only observation of a throwing function, callback-exception containment,
 *     internal-function observation.
 *   - "throw-with-end": pins the documented hard limitation - an END handler attached to a
 *     function that throws is invoked by the engine during unwinding, which ext/ffi aborts
 *     ("Throwing from FFI callbacks is not allowed"). The child process is expected to die.
 */

use ZEngine\Core;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\System\ExecutionData;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

Core::preload();

$out = getenv('ZOBS_OUT');
assert(is_string($out) && $out !== '');
$report = static function (string $line) use ($out): void {
    file_put_contents($out, $line . "\n", FILE_APPEND);
};

$report('PRELOADED=' . (Core::isPreloaded() ? '1' : '0'));
$report('USER_ENABLED=' . (Core::isObserverEnabled(true) ? '1' : '0'));
$report('INTERNAL_ENABLED=' . (Core::isObserverEnabled(false) ? '1' : '0'));
$report('OBSERVER_COUNT=' . Core::observerFcallObserverCount());

// Targets are compiled here - after startup, on the observed side of the boundary
include __DIR__ . '/observerFiringTargets.php';

$events  = [];
$hookFor = static function (string $name) use (&$events) {
    return Core::observeFunction(
        (new ReflectionFunction($name))->getRawFunctionPointer(),
        static function (ExecutionData $frame) use (&$events, $name): void {
            $events[] = "begin:{$name}";
        },
        static function (ExecutionData $frame, ?ReflectionValue $return) use (&$events, $name): void {
            $rendered = 'null';
            if ($return !== null) {
                $native = null;
                $return->getNativeValue($native);
                $rendered = var_export($native, true);
            }
            $events[] = "end:{$name}={$rendered}";
        },
    );
};

if (getenv('ZOBS_SCENARIO') === 'throw-with-end') {
    // Deliberately attach an END handler to a throwing function: the engine will invoke the
    // FFI end trampoline during unwinding and ext/ffi aborts the process. Pinned by the test.
    $hookFor('zengine_observed_thrower');
    $report('THROW_WITH_END=armed');
    try {
        zengine_observed_thrower();
    } catch (\RuntimeException $exception) {
        // Never reached: ext/ffi aborts before the catch can run
        $report('THROW_WITH_END=caught');
    }
    $report('THROW_WITH_END=survived');

    return;
}

// --- 1. begin/end fire with the return value ---------------------------------
$hook   = $hookFor('zengine_observed_simple');
$result = zengine_observed_simple(21);
$report("SIMPLE_RESULT={$result}");
$report('SIMPLE_EVENTS=' . implode(',', $events));

// --- 2. uninstall detaches cleanly -------------------------------------------
$hook->uninstall();
$events = [];
$silent = zengine_observed_simple(5);
$report("AFTER_UNINSTALL_RESULT={$silent}");
$report('AFTER_UNINSTALL_EVENTS=' . implode(',', $events));

// --- 3. nested call ordering --------------------------------------------------
$events    = [];
$outerHook = $hookFor('zengine_observed_outer');
$innerHook = $hookFor('zengine_observed_inner');
$nested    = zengine_observed_outer(1);
$report("NESTED_RESULT={$nested}");
$report('NESTED_EVENTS=' . implode(',', $events));
$innerHook->uninstall();
$outerHook->uninstall();

// --- 4. exception in the observed function (begin-only hook) ------------------
$events    = [];
$throwHook = Core::observeFunction(
    (new ReflectionFunction('zengine_observed_thrower'))->getRawFunctionPointer(),
    static function (ExecutionData $frame) use (&$events): void {
        $events[] = 'begin:zengine_observed_thrower';
    },
);
try {
    zengine_observed_thrower();
} catch (\RuntimeException $exception) {
    $report('THROW_CAUGHT=' . $exception->getMessage());
}
$report('THROW_EVENTS=' . implode(',', $events));
$throwHook->uninstall();

// --- 5. exception in the begin callback is contained ---------------------------
$events  = [];
$warning = '';
set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
    $warning = $message;

    return true;
}, E_USER_WARNING);
$brokenHook = Core::observeFunction(
    (new ReflectionFunction('zengine_observed_simple'))->getRawFunctionPointer(),
    static function (): void {
        throw new \LogicException('callback exploded');
    },
    static function () use (&$events): void {
        $events[] = 'end-after-broken-begin';
    },
);
$contained = zengine_observed_simple(3);
restore_error_handler();
$report("CONTAINED_RESULT={$contained}");
$report("CONTAINED_WARNING={$warning}");
$report('CONTAINED_EVENTS=' . implode(',', $events));
$brokenHook->uninstall();

// --- 6. internal function observation ------------------------------------------
$events       = [];
$internalHook = Core::observeFunction(
    (new ReflectionFunction('strrev'))->getRawFunctionPointer(),
    static function (ExecutionData $frame) use (&$events): void {
        $events[] = 'begin:strrev';
    },
    static function (ExecutionData $frame, ?ReflectionValue $return) use (&$events): void {
        $native = null;
        if ($return !== null) {
            $return->getNativeValue($native);
        }
        $events[] = 'end:strrev=' . var_export($native, true);
    },
);
$reversed = strrev('abc');
$report("INTERNAL_RESULT={$reversed}");
$report('INTERNAL_EVENTS=' . implode(',', $events));
$internalHook->uninstall();

$report('DONE');
