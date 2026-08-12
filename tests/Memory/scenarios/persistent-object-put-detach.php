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
use ZEngine\Type\ObjectEntry;
use ZEngine\Type\PersistentObjectFactory;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

class FlatCandidate
{
    public int $counter = 0;

    public bool $enabled = true;
}

// One immortal persistent clone attached and detached many times: simulates the
// per-request lifecycle of a persistent object within a single process
$source          = new FlatCandidate();
$source->counter = 42;

$sourceValue = new ReflectionValue($source);
$clone       = PersistentObjectFactory::persistentClone($sourceValue->getRawObject());
$sourceValue->release();
unset($source);

$store = Core::$executor->objectStore;

for ($index = 0; $index < 500; $index++) {
    $handle = $store->put($clone);

    $entry = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, \ZEngine\Type\StructArray::at($clone));
    $entry->getNativeValue($alias);
    $entry->release();

    if ($alias->counter !== 42 || spl_object_id($alias) !== $handle) {
        throw new RuntimeException('Persistent clone round-trip failed');
    }
    unset($alias);

    if (ObjectEntry::fromCData($clone)->getReferenceCount() !== PersistentObjectFactory::PIN_BASELINE) {
        throw new RuntimeException('Refcount drifted from the pin baseline');
    }

    $store->recycle($handle);
}

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
