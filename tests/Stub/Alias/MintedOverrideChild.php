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
 * Inherits m() from MintedOverrideBase; receives an override adopting a closure-swapped donor -
 * owned by RedefineMintedStaticsTest::testOverrideAdoptingAClosureSwappedMethodGetsItsOwnDuplicate
 */
class MintedOverrideChild extends MintedOverrideBase {}
