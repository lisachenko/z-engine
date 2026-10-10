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
 * Target of the override, linked - together with its subclass ChangedEarlySub - BEFORE the
 * override is published (AddMethodChangedFlagTest::testOverrideIsSharedIntoASubclassLinkedBeforeWithTheChangedFlag)
 */
class ChangedEarlyLeaf extends ChangedEarlyMid {}
