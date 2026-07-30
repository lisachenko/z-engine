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

use ZEngine\ClassExtension\Hook\ReadPropertyHook;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Stub\TestClass;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

$refClass = new ReflectionClass(TestClass::class);
$refClass->setCreateObjectHandler(Closure::fromCallable([ObjectCreateTrait::class, '__init']));
$refClass->setReadPropertyHandler(function (ReadPropertyHook $hook) {
    return $hook->proceed() + 1;
});

$instance = new TestClass();
if ($instance->property !== 43) {
    throw new RuntimeException('Hook is not active');
}
unset($instance);

// Explicit shutdown restores every engine pointer; a second call must be a no-op
Core::shutdown();
Core::shutdown();

$plain = new TestClass();
if ($plain->property !== 42) {
    throw new RuntimeException('Engine pointers were not restored');
}
unset($plain);

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
