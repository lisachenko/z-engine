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
use ZEngine\Type\ClosureEntry;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

$closure = function () {
    return get_class($this);
};

$entry = new ClosureEntry($closure);
$entry->setCalledScope(ArrayObject::class);
for ($index = 0; $index < 1000; $index++) {
    // Every call releases the previously bound object and references the new one
    $entry->setThis(new ArrayObject([$index]));
}

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
