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
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\Reflection\ReflectionMethod;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

function churn_function(): string
{
    return 'original';
}

class ChurnClass
{
    public function target(): string
    {
        return 'original';
    }
}

$refFunction = new ReflectionFunction('churn_function');
$refMethod   = new ReflectionMethod(ChurnClass::class, 'target');
$instance    = new ChurnClass();

// All swapped functions are called through runtime-opaque names: since PHP 8.6
// the opcache optimizer constant-folds (and devirtualizes) calls it can resolve
// at compile time - even through local variables holding constant strings - so a
// foldable call site would never consult the swapped body again. Deriving the
// names from $argv keeps them opaque to SCCP.
$callFunction = $argv[99] ?? 'churn_function';
$callMethod   = $argv[99] ?? 'target';
if (!is_callable($callFunction)) {
    throw new RuntimeException('churn_function is not callable');
}

// Every cycle compiles a brand-new body, swaps it in and must free the previous
// one: the debug leak gate fails on any block the swap machinery loses
for ($index = 0; $index < 500; $index++) {
    $body = eval("return function (): string { return 'fn{$index}'; };");
    assert($body instanceof Closure);
    $refFunction->redefine($body);
    unset($body);
    if ($callFunction() !== "fn{$index}") {
        throw new RuntimeException('Wrong function body after redefine');
    }

    $body = eval("return function (): string { return 'method{$index}'; };");
    assert($body instanceof Closure);
    $refMethod->redefine($body);
    unset($body);
    if ($instance->{$callMethod}() !== "method{$index}") {
        throw new RuntimeException('Wrong method body after redefine');
    }
}

// Static variables path: the entry duplicates the donor's defaults table and the
// swap releases the previous duplicate each cycle
function churn_counter(): int
{
    return -1;
}
$refCounter  = new ReflectionFunction('churn_counter');
$callCounter = $argv[99] ?? 'churn_counter';
if (!is_callable($callCounter)) {
    throw new RuntimeException('churn_counter is not callable');
}
for ($index = 0; $index < 100; $index++) {
    $body = eval("return function (): int { static \$count = {$index}; return ++\$count; };");
    assert($body instanceof Closure);
    $refCounter->redefine($body);
    unset($body);
    if ($callCounter() !== $index + 1) {
        throw new RuntimeException('Wrong static variable state after redefine');
    }
}

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
