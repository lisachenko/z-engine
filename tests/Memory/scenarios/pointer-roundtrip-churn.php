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
use ZEngine\Type\ObjectEntry;
use ZEngine\Type\StringEntry;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

for ($index = 0; $index < 500; $index++) {
    $literal = new StringEntry('immutable-literal');
    if ($literal->getStringValue() !== 'immutable-literal') {
        throw new RuntimeException('String literal round-trip failed');
    }
    $literal->release();

    $heap = StringEntry::fromString('heap-' . $index);
    if ($heap->getStringValue() !== 'heap-' . $index) {
        throw new RuntimeException('Heap string round-trip failed');
    }
    $heap->release();

    $object = new stdClass();
    $object->index = $index;

    $entry = new ObjectEntry($object);
    $entry->getNativeValue($native);
    if (!($native instanceof stdClass) || $native->index !== $index) {
        throw new RuntimeException('Object round-trip failed');
    }
    $entry->release();

    $node = Core::$compiler->parseString('echo "ok"; $value = ' . $index . ';', 'pointer-roundtrip.php');
    unset($node);
}

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
