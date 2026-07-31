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
 * Flat scalar-only class: a byte-copy-safe candidate for persistent object cloning
 */
class TestPersistentCandidate
{
    public int $counter = 0;

    public float $ratio = 1.5;

    public bool $enabled = false;
}
