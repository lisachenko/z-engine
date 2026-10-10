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

/**
 * Global function target for the preserved-alias redefine (required once by the test)
 */
function zengine_alias_fixture_fn(int $x): int
{
    return $x + 1;
}
