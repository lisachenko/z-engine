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
 * Linked BEFORE the override is published on ChangedEarlyLeaf: its table inherits the
 * CHANGED ChangedEarlyMid::m() entry and must follow the override, bit included - or the
 * publication is refused for a shared-memory leaf
 * (AddMethodChangedFlagTest::testOverrideIsSharedIntoASubclassLinkedBeforeWithTheChangedFlag)
 */
class ChangedEarlySub extends ChangedEarlyLeaf {}
