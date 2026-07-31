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

/**
 * Dismantling side of the persistent-table lifecycle: create/fill/destroy in a loop must
 * leave neither request memory (the leak gate below) nor malloc'd blocks (the RSS check)
 * behind. Sealed and mutable, string-keyed and index-keyed, empty and filled tables all
 * go through the same drop path.
 */
$residentKiloBytes = static function (): int {
    foreach (file('/proc/self/status') ?: [] as $line) {
        if (str_starts_with($line, 'VmRSS:')) {
            return (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT);
        }
    }

    return 0;
};

$cycle = static function (bool $sealed): void {
    $table = PersistentHashTable::create();

    for ($index = 0; $index < 64; $index++) {
        $value = new ReflectionValue($index);
        $table->add('key-' . $index, $value);
        $table->addIndex($index, $value);
        $value->release();
    }

    // Deleting through both key flavours before the drop exercises the bucket paths too
    $table->delete('key-7');
    $table->deleteIndex(7);

    if ($sealed) {
        $table->markImmutable();

        $entry = ReflectionValue::newEntry(ReflectionValue::IS_ARRAY, $table->getRawValue()[0]);
        $entry->getNativeValue($native);
        $entry->release();
        if (($native['key-1'] ?? null) !== 1) {
            throw new RuntimeException('Sealed persistent table did not materialize correctly');
        }
        unset($native);
    }

    $table->destroy();
};

// An empty table keeps the shared sentinel: destroy() must free the struct only
PersistentHashTable::create()->destroy();

for ($iteration = 0; $iteration < 100; $iteration++) {
    $cycle($iteration % 2 === 0);
}

$baseline = $residentKiloBytes();
for ($iteration = 0; $iteration < 500; $iteration++) {
    $cycle($iteration % 2 === 0);
}
$growth = $residentKiloBytes() - $baseline;
if ($baseline > 0 && $growth > 2048) {
    throw new RuntimeException("Destroyed persistent tables retained {$growth} kB of process memory");
}

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
