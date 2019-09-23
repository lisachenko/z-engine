<?php
/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\Type;

/**
 * Interface for all refcounted entries
 */
interface ReferenceCountedInterface
{
    /**
     * Returns an internal reference counter value
     */
    public function getReferenceCount(): int;

    /**
     * Increments a reference counter, so this object will live more than current scope
     */
    public function incrementReferenceCount(): void;

    /**
     * Decrements a reference counter
     */
    public function decrementReferenceCount(): void;
}
