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
 * opcache.preload fixture for ObserverHookPreloadTest.
 *
 * Runs during engine startup - the only moment observer registration timing is available - and
 * records, into the file named by the ZOBS_OUT environment variable, what the observer machinery
 * looks like from the preload path on a stock build with no startup-time observer provider:
 *   - PRELOADED:        whether Core booted through the preload path,
 *   - OBSERVER_ENABLED: whether the engine fcall-observer machinery is enabled,
 *   - OBSERVE:          whether ObserverHook attached or refused with a typed exception.
 *
 * Output goes to a plain file rather than a stream: opening a php://stderr resource during preload
 * would leave a persistent resource that breaks preload finalization.
 */

use ZEngine\Core;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\System\Hook\ObserverException;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

Core::preload();

$out = getenv('ZOBS_OUT');
assert(is_string($out) && $out !== '');
$report = static function (string $line) use ($out): void {
    file_put_contents($out, $line . "\n", FILE_APPEND);
};

$report('PRELOADED=' . (Core::isPreloaded() ? '1' : '0'));
$report('OBSERVER_ENABLED=' . (Core::isObserverEnabled() ? '1' : '0'));

// strlen is a pre-existing internal function, so no user function has to be compiled inside the
// preload script (mixing Core::preload() with user function declarations breaks preloading).
$function = (new ReflectionFunction('strlen'))->getRawFunctionPointer();

try {
    Core::observeFunction($function, static function (): void {}, static function (): void {});
    $report('OBSERVE=attached');
} catch (ObserverException $exception) {
    $report('OBSERVE=rejected');
}
