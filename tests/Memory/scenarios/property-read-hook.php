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
    return $hook->proceed() * 2;
});

$instance = new TestClass();
// A dynamic property name defeats the VM inline cache, so the read_property hook
// (and its per-read allocation path) really runs on every iteration
$propertyName = 'property';
for ($index = 0; $index < 1000; $index++) {
    if ($instance->{$propertyName} !== 84) {
        throw new RuntimeException('Unexpected hooked property value');
    }
}
unset($instance);

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
