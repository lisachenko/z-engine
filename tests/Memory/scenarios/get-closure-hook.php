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

use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Stub\VirtualProxy;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

$refClass = new ReflectionClass(VirtualProxy::class);
$refClass->installExtensionHandlers();

// The stub does not implement __invoke (engine-level closure resolution is the feature
// under test), so the instance is annotated with its actual runtime behavior
/** @var VirtualProxy&callable(string=): string $proxy */
$proxy = new VirtualProxy('leak-check');
// Exercise every get_closure consumer in a loop: each dynamic invocation resolves a fresh
// closure through the hook (retained by the hook until the next resolution, addref'd by
// the VM for the call duration), the check-only path resolves without invoking, and
// Closure::fromCallable materializes a first-class closure from the resolved pointers
for ($index = 0; $index < 1000; $index++) {
    if ($proxy() !== 'invoked-leak-check') {
        throw new RuntimeException('Unexpected hooked invocation result');
    }
    if ($proxy("-{$index}") !== "invoked-leak-check-{$index}") {
        throw new RuntimeException('Unexpected hooked invocation result with arguments');
    }
    if (!is_callable($proxy)) {
        throw new RuntimeException('Hooked object must probe as callable');
    }
    $firstClass = Closure::fromCallable($proxy);
    if ($firstClass('!') !== 'invoked-leak-check!') {
        throw new RuntimeException('Unexpected first-class closure result');
    }
    unset($firstClass);
}
unset($proxy);

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
