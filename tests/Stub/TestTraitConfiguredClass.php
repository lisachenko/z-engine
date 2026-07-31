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

namespace ZEngine\Stub;

/**
 * Uses compile-time trait adaptations, so the engine-level alias/precedence
 * structures are populated by the compiler itself
 */
class TestTraitConfiguredClass
{
    use TestFirstConflictTrait;
    use TestSecondConflictTrait {
        TestFirstConflictTrait::conflicting insteadof TestSecondConflictTrait;
        TestSecondConflictTrait::conflicting as secondConflicting;
        greet as protected politeGreet;
    }
}
