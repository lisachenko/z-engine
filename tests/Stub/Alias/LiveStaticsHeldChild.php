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
 * Linked before LiveStaticsHeldBase::counter() is redefined: inherits the entry pointer and
 * then the alias bucket - owned by the same scenario as LiveStaticsHeldBase
 */
class LiveStaticsHeldChild extends LiveStaticsHeldBase {}
