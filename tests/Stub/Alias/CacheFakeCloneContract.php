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

namespace ZEngine\Stub\Alias;

/**
 * Declares the method CacheFakeCloneChild inherits: an interface prototype makes the engine
 * separate the inherited bucket into a struct copy of the parent entry at link time
 * (owned by RedefineRunTimeCacheTest::testEngineCloneOfASubclassLinkedAfterTheSwapGetsItsOwnCache)
 */
interface CacheFakeCloneContract
{
    public function greet(string $who): string;
}
