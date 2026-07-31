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
use ZEngine\Reflection\ReflectionValue;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

for ($index = 0; $index < 1000; $index++) {
    // Explicit release
    $value = new ReflectionValue('value ' . $index);
    $value->release();

    // Automatic release through the destructor
    $auto = new ReflectionValue(['nested' => $index]);
    unset($auto);

    // Borrowed entries own nothing and must stay free of side effects
    $container = ReflectionValue::newEntry(ReflectionValue::IS_STRING, (new ReflectionValue('temp ' . $index))->getRawString()[0]);
    $container->release();
}

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
