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
use ZEngine\Stub\TestChildClass;
use ZEngine\Stub\TestParentClass;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

// Detach churn: every class goes through the full removal - constants, properties
// (including the private parent shadow slot and the own override that adopted a parent
// slot), materialized static members and methods - then instances are created, mutated
// and destroyed. Instances never live across a parent transition (see the docblock of
// removeParentClass(): existing objects keep the old property slot layout).
$iterations = max(1, (int) (getenv('ZENGINE_SCENARIO_ITERATIONS') ?: 100));
for ($index = 0; $index < $iterations; $index++) {
    $childName       = 'ScenarioChild' . $index;
    $classDefinition = "class {$childName} extends \\ZEngine\\Stub\\TestParentClass {"
        . "    public const CHILD_CONST = 'child';"
        . '    public int $childProperty = 20;'
        . '    public int $parentProperty = 30;'
        . "    public static array \$childStaticProperty = ['child'];"
        // Real property opcodes compiled per class: mutating own properties after the
        // detachment exercises the slot layout exactly like ordinary user code would
        . '    public function mutateAndCount(): int {'
        . '        $this->childProperty += 1;'
        . '        $this->parentProperty += 1;'
        . '        return count(get_object_vars($this));'
        . '    }'
        . '}';
    eval($classDefinition);
    assert(class_exists($childName));
    $refNative = new \ReflectionClass($childName);

    // Touch the static member so the engine materializes the live statics table: the
    // detachment must then compact both the default and the materialized table
    $liveStatic = $refNative->getStaticPropertyValue('childStaticProperty');
    assert(is_array($liveStatic));
    $liveStatic[] = 'live';
    $refNative->setStaticPropertyValue('childStaticProperty', $liveStatic);

    $refClass = new ReflectionClass($childName);
    $refClass->removeParentClass();

    if (defined($childName . '::PARENT_CONST')) {
        throw new RuntimeException('Parent constant was not detached');
    }
    if (property_exists($childName, 'parentSecret')) {
        throw new RuntimeException('Parent property was not detached');
    }
    if ($refNative->getStaticPropertyValue('childStaticProperty') !== ['child', 'live']) {
        throw new RuntimeException('Own static member was corrupted');
    }

    $instance = new $childName();
    // The runtime-resolved method name keeps static analysis away from the eval'd class
    $mutator = $refNative->getMethod('mutateAndCount')->getName();
    if ($instance->$mutator() !== 2) {
        throw new RuntimeException('Own properties were corrupted');
    }
    unset($instance);
}

// Relink churn: removeParentClass() followed by setParent() must return the class to a
// fully consistent state without leaking the per-relink table reallocations
$refChild = new ReflectionClass(TestChildClass::class);
for ($index = 0; $index < 50; $index++) {
    $refChild->removeParentClass();
    $refChild->setParent(TestParentClass::class);
}
if (get_parent_class(TestChildClass::class) !== TestParentClass::class) {
    throw new RuntimeException('Parent was not restored after the relink churn');
}
$instance = new TestChildClass();
if (get_object_vars($instance) !== ['parentProperty' => 30, 'childProperty' => 20]) {
    throw new RuntimeException('Property state after the relink churn is corrupted');
}
unset($instance);

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
