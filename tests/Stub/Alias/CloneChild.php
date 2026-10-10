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
 * Autoloaded AFTER the first redefinition of CloneBase::m(): implementing CloneContract makes
 * its method table hold an engine clone of the inherited entry, not the entry pointer
 * (owned by RedefineInterfaceCloneTest::testCloneOfASubclassLinkedAfterTheFirstSwapFollowsTheNextOne)
 */
class CloneChild extends CloneBase implements CloneContract {}
