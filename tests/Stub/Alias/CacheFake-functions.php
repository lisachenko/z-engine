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
 * Global function target of RedefineRunTimeCacheTest::testFakeClosureOverARedefinedGlobalFunction
 * (required once by the test): redefined twice while a Closure::fromCallable() over it stays alive
 */
function zengine_cachefake_fn(string $who): string
{
    return 'fn:' . $who;
}

/**
 * Function donor for zengine_cachefake_fn(): the swap adopting it retires the minted cache
 */
function zengine_cachefake_fn_donor(string $who): string
{
    return 'donor:' . strtolower($who) . str_repeat('?', 2);
}
