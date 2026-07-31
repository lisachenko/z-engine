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
use ZEngine\Stub\NativeCollection;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

$refClass = new ReflectionClass(NativeCollection::class);
$refClass->installExtensionHandlers();

// The stub intentionally does not implement ArrayAccess/Countable (engine-level overloading
// is the feature under test), so the instance is annotated with its actual runtime behavior
/** @var NativeCollection&ArrayAccess<array-key, mixed>&Countable $collection */
$collection = new NativeCollection(['a', 'b']);
// Exercise every dimension handler path in a loop: the read path goes through the
// engine-provided rv slot on every iteration and the offset zvals (long and string)
// are owned by the caller, so any refcount slip shows up as a leak report
for ($index = 0; $index < 1000; $index++) {
    $collection[] = "value{$index}";
    if ($collection[0] !== 'a') {
        throw new RuntimeException('Unexpected hooked dimension read value');
    }
    if ($collection["key{$index}"] !== null) {
        throw new RuntimeException('Unknown string offset should read as null');
    }
    $collection["key{$index}"] = $index;
    if (!isset($collection["key{$index}"])) {
        throw new RuntimeException('Hooked dimension should be set');
    }
    if (empty($collection["key{$index}"]) && $index !== 0) {
        throw new RuntimeException('Hooked dimension should not be empty');
    }
    unset($collection["key{$index}"]);
    if (count($collection) !== 3 + $index) {
        throw new RuntimeException('Unexpected hooked count value');
    }
}
unset($collection);

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
