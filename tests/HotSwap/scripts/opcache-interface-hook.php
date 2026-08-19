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

use ZEngine\ClassExtension\Hook\InterfaceGetsImplementedHook;
use ZEngine\ClassExtension\Hook\WritePropertyHook;
use ZEngine\Core;
use ZEngine\OpCache\SharedMemoryException;
use ZEngine\Reflection\ReflectionClass;

require __DIR__ . '/../../../vendor/autoload.php';

if (!function_exists('opcache_get_status') || opcache_get_status(false) === false) {
    fwrite(STDERR, "opcache is not active in the child process\n");
    exit(2);
}

Core::init();

require __DIR__ . '/opcache-interface-hook-fixture.php';

$status        = opcache_get_status(true);
$cachedScripts = is_array($status) && isset($status['scripts']) && is_array($status['scripts'])
    ? $status['scripts']
    : [];
if (!isset($cachedScripts[realpath(__DIR__ . '/opcache-interface-hook-fixture.php')])) {
    fwrite(STDERR, "interface fixture was not cached by opcache\n");
    exit(2);
}

/**
 * Fails the probe with a diagnostic (exit code 1 = assertion failure)
 */
$fail = static function (string $message): never {
    fwrite(STDERR, "{$message}\n");
    exit(1);
};

$refInterface = new ReflectionClass(ZEngineShmHookInterface::class);
if (!$refInterface->isImmutable()) {
    // A mutable interface entry means opcache did not publish the fixture from
    // shared memory: the lazy-linking path under test would not be exercised
    fwrite(STDERR, "the interface is not an immutable (shared-memory) class entry\n");
    exit(2);
}

$observations = [
    'hook-fired'   => false,
    'lazy-copy'    => false,
    'guard-thrown' => false,
    'unexpected'   => '',
];
$refInterface->setInterfaceGetsImplementedHandler(
    static function (InterfaceGetsImplementedHook $hook) use (&$observations): int {
        $observations['hook-fired'] = true;
        // Nothing may throw OUT of an engine callback (issue #50): every outcome is
        // recorded and asserted after the linking completed
        try {
            $implementor               = $hook->getClass();
            $observations['lazy-copy'] = $implementor->isLazyLinkingCopy();
            $implementor->setWritePropertyHandler(static function (WritePropertyHook $propertyHook): mixed {
                return $propertyHook->getValue();
            });
        } catch (SharedMemoryException) {
            $observations['guard-thrown'] = true;
        } catch (\Throwable $throwable) {
            $observations['unexpected'] = $throwable::class . ': ' . $throwable->getMessage();
        }

        return Core::SUCCESS;
    },
);

require __DIR__ . '/opcache-interface-hook-implementor.php';

if ($observations['unexpected'] !== '') {
    $fail("unexpected failure inside the interface hook: {$observations['unexpected']}");
}
if (!$observations['hook-fired']) {
    $fail('the interface_gets_implemented hook did not fire when the implementor linked');
}
if (!$observations['lazy-copy']) {
    // The hook observed an ordinary entry: opcache's lazy-linking path (inheritance
    // cache) did not engage, so the guard has nothing to reject here. The parent
    // passes opcache.file_update_protection=0 exactly to prevent the usual cause -
    // freshly checked-out files being refused by the cache
    fwrite(STDERR, "the implementor was not observed as a lazy-linking copy\n");
    exit(2);
}
if (!$observations['guard-thrown']) {
    $fail('handler installation on the lazy-linking copy was not rejected (issue #238 would silently lose it)');
}
echo "lazy-linking-guard: ok\n";
echo "INTERFACE HOOK OK\n";
