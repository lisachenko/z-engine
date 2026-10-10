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
 * Linked BEFORE CloneAliasBase::m() is redefined with a preserved alias: holds an engine clone
 * of the inherited entry, and must receive the alias the preserved body calls
 * (owned by RedefineInterfaceCloneTest::testPreservedAliasReachesTheCloneHolderLinkedBefore)
 */
class CloneAliasChild extends CloneAliasBase implements CloneContract {}
