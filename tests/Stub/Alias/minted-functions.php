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
 * Global function swapped with closures declaring static variables and preserved under an alias
 * (required once by RedefineMintedStaticsTest::testAliasOfAClosureSwappedFunctionStartsFromItsOwnDuplicateOfTheDefaults)
 */
function zengine_minted_fixture_fn(): int
{
    return -1;
}

/**
 * Global function redefined hundreds of times with closures declaring static variables
 * (required once by RedefineMintedStaticsTest::testConsecutiveClosureDonorSwapsOfAFunctionAreMemoryFlat)
 */
function zengine_minted_churn_fn(): int
{
    return -1;
}
