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
 * Observed target functions for the observerFiringProbe fixture.
 *
 * Kept in a separate file (included by the preload probe after Core::preload()) for two reasons:
 * the file is compiled AFTER engine startup, i.e. on the observed side of the compile-time
 * boundary, and declaring functions directly inside the Core::preload() script breaks preload
 * finalization.
 */

function zengine_observed_simple(int $value): int
{
    return $value * 2;
}

function zengine_observed_inner(int $value): int
{
    return $value + 1;
}

function zengine_observed_outer(int $value): int
{
    return zengine_observed_inner($value) + 10;
}

function zengine_observed_thrower(): void
{
    throw new RuntimeException('observed failure');
}
