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
use ZEngine\Stub\TestClass;
use ZEngine\Stub\TestInterface;
use ZEngine\Stub\TestTrait;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

$refClass = new ReflectionClass(TestClass::class);
for ($index = 0; $index < 200; $index++) {
    $refClass->addInterfaces(TestInterface::class);
    $refClass->removeInterfaces(TestInterface::class);
    $refClass->addTraits(TestTrait::class);
    $refClass->removeTraits(TestTrait::class);
}

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
