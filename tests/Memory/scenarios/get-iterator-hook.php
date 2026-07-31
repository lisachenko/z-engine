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

use ZEngine\ClassExtension\Hook\IteratorBridge;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Stub\NativeIterable;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

$refClass = new ReflectionClass(NativeIterable::class);
$refClass->installExtensionHandlers();

// Iterator lifecycle is the leak-prone part: every cycle allocates a zend_object_iterator
// (request allocator, freed by the engine objects store) and caches the current value in
// its data slot with full assignment semantics. Both full and broken-out iterations must
// release exactly one reference per handed-out value and destroy the iterator.
// The stub intentionally does not implement \Traversable (engine-level iteration is the
// feature under test), so the instance is annotated with its actual runtime behavior
/** @var NativeIterable&Traversable<string, string> $iterable */
$iterable = new NativeIterable(['a' => 'first', 'b' => 'second', 'c' => 'third']);
for ($index = 0; $index < 1000; $index++) {
    // Full iteration cycle (rewind/valid/current/key/next until the end + dtor)
    $seen = [];
    foreach ($iterable as $key => $value) {
        $seen[$key] = $value;
    }
    if ($seen !== ['a' => 'first', 'b' => 'second', 'c' => 'third']) {
        throw new RuntimeException('Unexpected hooked iteration result');
    }

    // Broken-out iteration cycle: FE_FREE must release the engine iterator mid-flight,
    // while it still caches the current value in its data slot
    foreach ($iterable as $key => $value) {
        if ($value === 'second') {
            break;
        }
    }

    // Nested iteration over the same instance: two live iterators at once
    $count = 0;
    foreach ($iterable as $outer) {
        foreach ($iterable as $inner) {
            $count++;
        }
        break; // and break out of the outer loop with the inner one completed
    }
    if ($count !== 3) {
        throw new RuntimeException('Unexpected nested iteration count');
    }

    if (IteratorBridge::activeIteratorCount() !== 0) {
        throw new RuntimeException('Engine iterator was not released after the loop');
    }
}
unset($iterable);

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
