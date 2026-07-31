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
use ZEngine\Reflection\ReflectionConstant;
use ZEngine\System\OpCode;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

// Constant table churn: define -> reflect -> remove must be fully released by the
// engine destructor (free_zend_constant releases the value, the name and the struct)
for ($index = 0; $index < 500; $index++) {
    $name = "ZENGINE_CHURN_CONSTANT_{$index}";
    define($name, "payload-{$index}-" . str_repeat('x', 32));

    $reflection = new ReflectionConstant($name);
    if ($reflection->isPersistent() || !$reflection->remove()) {
        echo 'FAIL: user constant was not removable', PHP_EOL;
        exit(1);
    }
    if (defined($name)) {
        echo 'FAIL: constant still defined after remove', PHP_EOL;
        exit(1);
    }
}

// Borrowed reflection over persistent constants owns nothing
for ($index = 0; $index < 500; $index++) {
    $reflection = new ReflectionConstant('PHP_VERSION');
    $reflection->getReflectionValue()->getNativeValue($value);
    unset($reflection, $value);
}

// suppressCurrentException()/zend_clear_exception churn, including from an
// engine-invoked opcode handler context. The live clearing path (a pending
// EG(exception) being released) is not reachable from userland - the engine
// never runs PHP code with an exception in flight - so this locks down that
// the guarded no-op paths allocate and leak nothing.
$hook = OpCode::setHandler(OpCode::ADD, static function ($scope): int {
    Core::$executor->suppressCurrentException();

    return Core::ZEND_USER_OPCODE_DISPATCH;
});
function zengine_churn_add(int $a, int $b): int
{
    return $a + $b;
}
for ($index = 0; $index < 500; $index++) {
    if (zengine_churn_add($index, $index) !== 2 * $index) {
        echo 'FAIL: hooked addition broke', PHP_EOL;
        exit(1);
    }
    Core::$executor->suppressCurrentException();
    Core::call('zend_clear_exception');
}
$hook->uninstall();

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
