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
use ZEngine\Type\PersistentHashTable;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

// The persistent tables themselves are immortal-by-design malloc blocks; the gate
// verifies that no REQUEST memory leaks while feeding and reading them
$registry = new PersistentHashTable();

for ($index = 0; $index < 200; $index++) {
    $value = new ReflectionValue($index);
    $registry->add('key-' . ($index % 10), $value);
    $registry->addIndex($index % 10, $value);
    $value->release();

    $found = $registry->find('key-' . ($index % 10));
    if ($found === null) {
        throw new RuntimeException('Persistent table lookup failed');
    }
    $found->getNativeValue($nativeValue);
    if ($nativeValue !== $index) {
        throw new RuntimeException('Persistent table round-trip failed');
    }

    foreach ($registry->getIterator() as $item) {
        // Borrowed views only: iteration must not addref anything
    }
}

// Sealed tables materialize as non-refcounted zvals: reading and copy-on-writing
// them from userland must produce no request-memory leaks either
$registry->markImmutable();
for ($index = 0; $index < 200; $index++) {
    $entry = ReflectionValue::newEntry(ReflectionValue::IS_ARRAY, $registry->getRawValue()[0]);
    $entry->getNativeValue($native);
    $entry->release();

    $copy          = $native;
    $copy['key-1'] = 'mutated ' . $index;
    unset($copy, $native);
}

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
