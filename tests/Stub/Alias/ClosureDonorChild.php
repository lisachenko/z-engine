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
 * Autoloaded AFTER ClosureDonorCounter::counter() was redefined: inherits the redefined entry
 * and the closure-backed method, then overrides inherited() with that closure-backed method as
 * the donor - owned by the same scenario as ClosureDonorCounter
 */
class ClosureDonorChild extends ClosureDonorCounter {}
