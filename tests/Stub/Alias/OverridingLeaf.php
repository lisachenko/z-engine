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
 * Linked BEFORE OverridingBase::m() is redefined; inherits the override of OverridingSub,
 * so its table never held the base entry at all
 */
class OverridingLeaf extends OverridingSub {}
