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
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\Core;
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

if (!Core::canDeclineInheritanceCachePublication()) {
    // The engine binding of this platform must export zend_inheritance_cache_add:
    // without the interception the handlers installed below would be silently lost
    $fail('inheritance-cache decline is unavailable (stale engine definitions? run composer gen-headers)');
}

$observations = [
    'hook-fired'   => false,
    'lazy-copy'    => false,
    'lazy-address' => 0,
    'unexpected'   => '',
];
$refInterface->setInterfaceGetsImplementedHandler(
    static function (InterfaceGetsImplementedHook $hook) use (&$observations): int {
        $observations['hook-fired'] = true;
        // Nothing may throw OUT of an engine callback (issue #50): every outcome is
        // recorded and asserted after the linking completed
        try {
            $implementor                  = $hook->getClass();
            $observations['lazy-copy']    = $implementor->isLazyLinkingCopy();
            $observations['lazy-address'] = $implementor->getAddress();
            // The issue #238 reproducer: handlers installed mid-linking must actually
            // stick - the decline of the inheritance-cache publication (issue #241)
            // keeps this very class entry process-local, so both the create_object
            // slot and the address-keyed handlers block stay valid after linking
            $implementor->setCreateObjectHandler(Closure::fromCallable([ObjectCreateTrait::class, '__init']));
            $implementor->setWritePropertyHandler(static function (WritePropertyHook $propertyHook): mixed {
                $written = $propertyHook->getValue();

                return is_int($written) ? $written * 2 : $written;
            });
        } catch (\Throwable $throwable) {
            $observations['unexpected'] = $throwable::class . ': ' . $throwable->getMessage();
        }

        return Core::SUCCESS;
    },
);

require __DIR__ . '/opcache-interface-hook-implementor.php';
require __DIR__ . '/opcache-interface-hook-sibling.php';

if ($observations['unexpected'] !== '') {
    $fail("unexpected failure inside the interface hook: {$observations['unexpected']}");
}
if (!$observations['hook-fired']) {
    $fail('the interface_gets_implemented hook did not fire when the implementor linked');
}
if (!$observations['lazy-copy']) {
    // The hook observed an ordinary entry: opcache's lazy-linking path (inheritance
    // cache) did not engage, so the decline has nothing to keep alive here. The parent
    // passes opcache.file_update_protection=0 exactly to prevent the usual cause -
    // freshly checked-out files being refused by the cache
    fwrite(STDERR, "the implementor was not observed as a lazy-linking copy\n");
    exit(2);
}

// The declined class must still be the very entry the hook installed handlers on:
// process-local (not swapped for a published shared-memory copy) and fully linked
$refImplementor = new ReflectionClass(ZEngineShmHookImplementor::class);
if ($refImplementor->getAddress() !== $observations['lazy-address']) {
    $fail('the class table no longer publishes the entry the handlers were installed on (publication was not declined)');
}
if ($refImplementor->isImmutable()) {
    $fail('the implementor was published into shared memory although its publication should have been declined');
}
if ($refImplementor->isLazyLinkingCopy()) {
    $fail('the implementor entry never finished linking (ZEND_ACC_LINKED is still clear)');
}

// ...and the handlers installed mid-linking actually fire: the write_property
// handler doubles every integer written to the instance (this exact interaction
// was silently lost before the decline - issue #238). The write runs behind an
// opaque boundary: the installed handler decides the stored value at runtime,
// which no analyser can see
$readBack        = static fn(ZEngineShmHookImplementor $subject): int => $subject->value;
$instance        = new ZEngineShmHookImplementor();
$instance->value = 21;
if ($readBack($instance) !== 42) {
    $fail('the write_property handler installed during lazy linking did not fire (value: ' . $readBack($instance) . ')');
}
echo "lazy-linking-handlers: ok\n";

// The untouched sibling must NOT pay for the decline: its publication delegates to
// opcache unchanged, so its class-table entry is the immutable shared-memory copy
// the inheritance cache returned
$refSibling = new ReflectionClass(ZEngineShmHookSibling::class);
if ($refSibling->isImmutable()) {
    echo "sibling-cache-reuse: ok\n";
} else {
    $fail('the untouched sibling class was not published into the inheritance cache (the interception over-declined)');
}

echo "INTERFACE HOOK OK\n";
