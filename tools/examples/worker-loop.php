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

/**
 * Worker-loop soak test: simulates a long-running worker that keeps using z-engine APIs
 * for thousands of iterations and asserts that request memory usage stays flat after
 * warm-up. Exits non-zero on growth, so it can act as a CI gate.
 *
 * Usage: php -d ffi.enable=1 tools/examples/worker-loop.php [iterations]
 */

use ZEngine\ClassExtension\Hook\ReadPropertyHook;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Stub\TestClass;
use ZEngine\Stub\TestInterface;
use ZEngine\Stub\TestTrait;
use ZEngine\Type\ClosureEntry;
use ZEngine\Type\PersistentHashTable;
use ZEngine\Type\PersistentObjectFactory;
use ZEngine\Type\StringEntry;

require __DIR__ . '/../../vendor/autoload.php';

Core::init();

$totalIterations  = (int) ($argv[1] ?? 10_000);
$warmupIterations = min(1_000, intdiv($totalIterations, 10));
$allowedGrowth    = 64 * 1024; // bytes after warm-up

// Install hooks once at worker boot (the recommended worker-loop model)
$refClass = new ReflectionClass(TestClass::class);
$refClass->setCreateObjectHandler(Closure::fromCallable([ObjectCreateTrait::class, '__init']));
$refClass->setReadPropertyHandler(static function (ReadPropertyHook $hook) {
    return $hook->proceed() * 2;
});

$closure = static function (): int {
    return 42;
};
$closureEntry = new ClosureEntry($closure);
$propertyName = 'property';
$baseline     = null;

// Persistent structures are minted once at boot (immortal-by-design) and then
// attached/detached every iteration like a per-request lifecycle would
$persistentTable = new PersistentHashTable();
$seedValue       = new ReflectionValue(42);
$persistentTable->add('seed', $seedValue);
$seedValue->release();
$persistentTable->markImmutable();

$candidate          = new ZEngine\Stub\TestPersistentCandidate();
$candidate->counter = 42;
$candidateValue     = new ReflectionValue($candidate);
$persistentClone    = PersistentObjectFactory::persistentClone($candidateValue->getRawObject());
$candidateValue->release();
unset($candidate, $candidateValue);

for ($iteration = 1; $iteration <= $totalIterations; $iteration++) {
    // Value wrapper churn
    $value = new ReflectionValue('iteration payload ' . $iteration);
    $value->release();

    $string = StringEntry::fromString('worker string ' . $iteration);
    if ($string->getStringValue() !== 'worker string ' . $iteration) {
        fwrite(STDERR, "String round-trip failed at iteration {$iteration}\n");
        exit(1);
    }
    unset($string);

    // Hooked object creation and property reads (dynamic name defeats the inline cache)
    $instance = new TestClass();
    if ($instance->{$propertyName} !== 84) {
        fwrite(STDERR, "Hooked property read failed at iteration {$iteration}\n");
        exit(1);
    }
    unset($instance);

    // Closure this-rebinding
    $closureEntry->setThis(new ArrayObject([$iteration]));

    // Persistent object attach / materialize / detach cycle plus immutable-array COW
    $handle = Core::$executor->objectStore->put($persistentClone);
    $entry  = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $persistentClone[0]);
    $entry->getNativeValue($persistentAlias);
    $entry->release();
    if ($persistentAlias->counter !== 42) {
        fwrite(STDERR, "Persistent clone read failed at iteration {$iteration}\n");
        exit(1);
    }
    unset($persistentAlias);
    Core::$executor->objectStore->recycle($handle);

    $tableEntry = ReflectionValue::newEntry(ReflectionValue::IS_ARRAY, $persistentTable->getRawValue()[0]);
    $tableEntry->getNativeValue($persistentCopy);
    $tableEntry->release();
    $persistentCopy['seed'] = $iteration; // must copy-on-write, never touch the block
    unset($persistentCopy);

    // AST parse / destroy cycle
    if ($iteration % 10 === 0) {
        $node = Core::$compiler->parseString('echo ' . $iteration . ' + 1;', 'worker.php');
        unset($node);
    }

    // Interface / trait replacement churn
    if ($iteration % 25 === 0) {
        $refClass->addInterfaces(TestInterface::class);
        $refClass->removeInterfaces(TestInterface::class);
        $refClass->addTraits(TestTrait::class);
        $refClass->removeTraits(TestTrait::class);
    }

    if ($iteration === $warmupIterations) {
        gc_collect_cycles();
        $baseline = memory_get_usage();
    }
    if ($iteration % 1_000 === 0 && $baseline !== null) {
        gc_collect_cycles();
        $delta = memory_get_usage() - $baseline;
        printf("iteration %6d: delta %+d bytes\n", $iteration, $delta);
    }
}

gc_collect_cycles();
$finalDelta = memory_get_usage() - ($baseline ?? memory_get_usage());

printf("final delta after %d iterations: %+d bytes (allowed %d)\n", $totalIterations, $finalDelta, $allowedGrowth);

if ($finalDelta > $allowedGrowth) {
    fwrite(STDERR, "FAIL: memory grew beyond the allowed threshold\n");
    exit(1);
}

echo "SOAK OK\n";
