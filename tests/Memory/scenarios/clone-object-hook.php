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

use ZEngine\ClassExtension\Hook\CloneObjectHook;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Stub\TestClass;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

$refClass = new ReflectionClass(TestClass::class);
$refClass->setCreateObjectHandler(Closure::fromCallable([ObjectCreateTrait::class, '__init']));
$refClass->setCloneObjectHandler(function (CloneObjectHook $hook): object {
    // proceed() exchanges the original handler's reference for a PHP-owned one, and
    // handle() transfers exactly one reference back to the VM - the leak-sensitive part
    return $hook->proceed();
});

$instance = new TestClass();
for ($index = 0; $index < 1000; $index++) {
    $clone = clone $instance;
    if ($clone === $instance || $clone->property !== 42) {
        throw new RuntimeException('Unexpected clone result');
    }
    unset($clone);
}
unset($instance);

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
