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
 * Linked BEFORE CloneEarlyBase::m() is redefined: holds an engine clone of the inherited entry
 * (owned by RedefineInterfaceCloneTest::testCloneOfASubclassLinkedBeforeFollowsTheRedefinition)
 */
class CloneEarlyChild extends CloneEarlyBase implements CloneContract {}
