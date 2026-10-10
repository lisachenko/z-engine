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
 * Linked BEFORE OverridingBase2::m() is redefined; its table inherited the private
 * OverridingMid::mOriginal() bucket at link time
 */
class OverridingLeaf2 extends OverridingMid {}
