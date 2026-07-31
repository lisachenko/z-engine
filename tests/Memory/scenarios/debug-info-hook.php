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

use ZEngine\ClassExtension\Hook\GetDebugInfoHook;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Stub\TestClass;

require __DIR__ . '/../../../vendor/autoload.php';

/**
 * Local target with a __debugInfo magic method: the engine's default handler reports its
 * table as temporary (*is_temp = 1), so proceed() exercises the handed-over-reference
 * release path in addition to the borrowed-table path of plain classes
 */
class DebugInfoMagicTarget
{
    public int $value = 7;

    /**
     * @return array<string, int>
     */
    public function __debugInfo(): array
    {
        return ['magic' => $this->value];
    }
}

Core::init();

$handler = function (GetDebugInfoHook $hook): array {
    // proceed() materializes the default engine debug info (and releases a temporary
    // table when the original handler hands one over)
    $default = $hook->proceed();

    // The returned array is converted to a HashTable* reported with *is_temp = 1:
    // the engine caller owns and frees it after dumping - the leak-sensitive part
    return ['marker' => 'debug-info-hook', 'default' => $default];
};

$refClass = new ReflectionClass(TestClass::class);
$refClass->setCreateObjectHandler(Closure::fromCallable([ObjectCreateTrait::class, '__init']));
$refClass->setGetDebugInfoHandler($handler);

$refMagicClass = new ReflectionClass(DebugInfoMagicTarget::class);
$refMagicClass->setCreateObjectHandler(Closure::fromCallable([ObjectCreateTrait::class, '__init']));
$refMagicClass->setGetDebugInfoHandler($handler);

$instance = new TestClass();
$magic    = new DebugInfoMagicTarget();
for ($index = 0; $index < 1000; $index++) {
    ob_start();
    var_dump($instance);
    var_dump($magic);
    $output = (string) ob_get_clean();
    if (substr_count($output, 'debug-info-hook') !== 2) {
        throw new RuntimeException('Unexpected debug info output');
    }
}
unset($instance, $magic);

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
