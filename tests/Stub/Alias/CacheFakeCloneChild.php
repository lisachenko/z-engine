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
 * Autoloaded AFTER the first redefinition of CacheFakeCloneBase::greet(): the interface
 * forces an engine clone of the inherited entry into this method table
 * (owned by RedefineRunTimeCacheTest::testEngineCloneOfASubclassLinkedAfterTheSwapGetsItsOwnCache)
 */
class CacheFakeCloneChild extends CacheFakeCloneBase implements CacheFakeCloneContract {}
