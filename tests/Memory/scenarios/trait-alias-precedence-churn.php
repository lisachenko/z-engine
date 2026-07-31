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
use ZEngine\Stub\TestClass;
use ZEngine\Stub\TestTrait;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

// Add/remove churn over the tracked runtime allocations behind trait aliases and
// precedences: every zend_trait_alias/zend_trait_precedence structure, list and stored
// name must be released when the entry is removed again
$refClass = new ReflectionClass(TestClass::class);
for ($index = 0; $index < 200; $index++) {
    $refClass->addTraitAlias(TestTrait::class . '::foo', 'renamedFoo');
    $refClass->addTraitAlias('foo', 'visibilityFoo', Core::ZEND_ACC_PROTECTED);
    $refClass->addTraitPrecedence(TestTrait::class . '::foo', 'FirstExcludedTrait', 'SecondExcludedTrait');

    $refClass->removeTraitAlias('renamedFoo');
    $refClass->removeTraitAlias('visibilityFoo');
    $refClass->removeTraitPrecedence(TestTrait::class . '::foo');
}

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
