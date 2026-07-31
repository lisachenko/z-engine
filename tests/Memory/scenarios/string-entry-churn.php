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
use ZEngine\Type\StringEntry;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

for ($index = 0; $index < 1000; $index++) {
    $heap = new StringEntry('heap string ' . $index . str_repeat('x', 32));
    $copy = $heap->getStringValue();
    $heap->release();

    $owned = StringEntry::fromString('owned string ' . $index);
    if ($owned->getStringValue() !== 'owned string ' . $index) {
        throw new RuntimeException('String round-trip failed');
    }
    unset($owned);

    $interned = new StringEntry('interned');
    $interned->release();
}

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
