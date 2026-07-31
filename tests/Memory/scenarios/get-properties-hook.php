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

use ZEngine\ClassExtension\Hook\GetPropertiesHook;
use ZEngine\ClassExtension\ObjectCreateInterface;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Stub\VirtualProxy;

require __DIR__ . '/../../../vendor/autoload.php';

/**
 * Proxy whose reported property table carries object references, so the cycle collector
 * has to traverse (and be able to free) cycles running through hook-anchored tables
 */
class CyclicPropertiesProxy implements ObjectCreateInterface
{
    use ObjectCreateTrait;

    public ?object $peer = null;
}

Core::init();

/**
 * Snapshots the engine property table through an (array) cast: the mixed-typed seam
 * erases the declared-property array shape static analysis would infer (the runtime
 * table is defined by the get_properties hook, not by the declared properties)
 *
 * @return array<array-key, mixed>
 */
$propertySnapshot = static function (object $object): array {
    return (array) $object;
};

$refClass = new ReflectionClass(VirtualProxy::class);
$refClass->installExtensionHandlers();

// The stub does not implement Traversable (engine-level property overloading is the
// feature under test), so the instance is annotated with its actual runtime behavior
/** @var VirtualProxy&Traversable<string, mixed> $proxy */
$proxy = new VirtualProxy('leak-check');
// Exercise every get_properties consumer in a loop: each iteration mutates the object
// (forcing a fresh anchored table, the previous one must be released), then reads the
// table back through (array), get_object_vars() and foreach
for ($index = 0; $index < 1000; $index++) {
    $proxy->subject = "value{$index}";
    $castResult     = $propertySnapshot($proxy);
    if ($castResult !== ['subject' => "value{$index}", 'virtual' => true]) {
        throw new RuntimeException('Unexpected hooked (array) cast result');
    }
    if (get_object_vars($proxy) !== $castResult) {
        throw new RuntimeException('Unexpected hooked get_object_vars() result');
    }
    $iterated = [];
    foreach ($proxy as $key => $value) {
        $iterated[$key] = $value;
    }
    if ($iterated !== $castResult) {
        throw new RuntimeException('Unexpected hooked foreach result');
    }
}

// Push the hooked object into the GC root buffer and run the collector: the redirected
// get_gc implementation must serve the anchored table without entering userland
$container = [$proxy, $proxy];
unset($container);
gc_collect_cycles();
if ($propertySnapshot($proxy)['subject'] !== 'value999') {
    throw new RuntimeException('Hooked object must survive a garbage collection run');
}
unset($proxy);

// Documented limitation (see GetPropertiesHook): the collector sees hooked objects only
// through their reported table, so declared-property references are invisible to it and
// cycles through them are not collected. The weak references prove the objects survive
// the collector; breaking the cycle explicitly then reclaims everything deterministically.
$cyclicRef = new ReflectionClass(CyclicPropertiesProxy::class);
$cyclicRef->setCreateObjectHandler(Closure::fromCallable([ObjectCreateTrait::class, '__init']));
$cyclicRef->setGetPropertiesHandler(function (GetPropertiesHook $hook) {
    $instance = $hook->getObject();
    assert($instance instanceof CyclicPropertiesProxy);

    return ['peer' => $instance->peer];
});

$first        = new CyclicPropertiesProxy();
$second       = new CyclicPropertiesProxy();
$first->peer  = $second;
$second->peer = $first;
// Anchor both tables (each table now also owns a reference to the peer object)
$unused = [(array) $first, (array) $second];
unset($unused);

$weakFirst  = WeakReference::create($first);
$weakSecond = WeakReference::create($second);
unset($first, $second);
gc_collect_cycles();

$first  = $weakFirst->get();
$second = $weakSecond->get();
if (!$first instanceof CyclicPropertiesProxy || !$second instanceof CyclicPropertiesProxy) {
    throw new RuntimeException('Declared-property cycles are documented as invisible to the collector');
}
// Break the cycle and re-anchor peer-free tables so plain refcounting reclaims everything
$first->peer  = null;
$second->peer = null;
$unused       = [(array) $first, (array) $second];
unset($unused, $first, $second);
if ($weakFirst->get() !== null || $weakSecond->get() !== null) {
    throw new RuntimeException('Breaking the cycle must make the objects reclaimable');
}

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
