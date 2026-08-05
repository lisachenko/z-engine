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

namespace ZEngine\Stub;

/**
 * Parent of the slot-substitution template: its declarations are shared with every child,
 * so addressing them from a child must be rejected
 */
class TestSlotSpecializationBase
{
    public mixed $inheritedValue = null;

    public function inheritedSetter(mixed $value): void
    {
        $this->inheritedValue = $value;
    }
}
