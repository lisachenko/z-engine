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
use ZEngine\HotSwap\HotSwap;
use ZEngine\HotSwap\HotSwapException;
use ZEngine\Reflection\ReflectionClass as ZEngineReflectionClass;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

$template = <<<'PHP'
class HotChurnClass
{
    public const VERSION = %d;
    public int $counter = %d;
    public function greet(): string { return 'hello %d'; }
    public function added(): int { return %d; }
}
PHP;

eval('class HotChurnClass { public const VERSION = 0; public int $counter = 0; '
    . 'public function greet(): string { return "hello start"; } }');

// The class only exists at runtime (eval), so all member access goes through the
// native reflection API to stay statically analyzable
$className    = 'HotChurnClass';
$makeInstance = static function () use ($className): object {
    // The z-engine reflection accepts runtime-declared class names as-is
    return (new ZEngineReflectionClass($className))->newInstance();
};
$callMethod = static fn(object $instance, string $method): mixed
    => (new ReflectionMethod($instance, $method))->invoke($instance);

// First delta adds a method; subsequent deltas keep swapping bodies, constants
// and defaults in place - the gate fails on anything a swap cycle loses.
// (Method removal is deliberately absent here: a removed body stays allocated by
// design for warmed-up caches, see the immortal table in docs/long-running.md.)
for ($index = 1; $index <= 100; $index++) {
    $delta = HotSwap::prepare($className, sprintf($template, $index, $index, $index, $index));
    $delta->apply();
    $instance = $makeInstance();
    if ($callMethod($instance, 'greet')                                        !== "hello {$index}"
        || $callMethod($instance, 'added')                                     !== $index
        || (new ReflectionProperty($instance, 'counter'))->getValue($instance) !== $index
        || constant("{$className}::VERSION")                                   !== $index
    ) {
        throw new RuntimeException("Wrong class state after hot swap {$index}");
    }
    unset($instance);
}

// A discarded delta must release its donor completely
HotSwap::prepare($className, sprintf($template, 999, 999, 999, 999))->discard();

// A failed apply must roll back without losing any staged resource. The failure is
// genuine: a delta that adds a method is applied twice against the same class, so the
// second apply hits a duplicate table key at publish time and rolls back everything
// it staged before that point.
eval('class HotChurnRollback { public function base(): int { return 1; } }');
$rollbackDelta1 = HotSwap::prepare(
    'HotChurnRollback',
    'class HotChurnRollback { public function base(): int { return 2; } public function extra(): int { return 3; } }',
);
$rollbackDelta2 = HotSwap::prepare(
    'HotChurnRollback',
    'class HotChurnRollback { public function base(): int { return 4; } public function extra(): int { return 5; } }',
);
$rollbackDelta1->apply();
try {
    $rollbackDelta2->apply();
    throw new RuntimeException('Apply was expected to fail on the duplicate method publish');
} catch (HotSwapException $exception) {
    // expected: staged operations rolled back, donor released
}
$rollbackInstance = (new ZEngineReflectionClass('HotChurnRollback'))->newInstance();
if ($callMethod($rollbackInstance, 'base') !== 2) {
    throw new RuntimeException('Rollback did not restore the previous body');
}

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
