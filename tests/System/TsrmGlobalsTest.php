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

namespace ZEngine\System;

use PHPUnit\Framework\TestCase;
use ZEngine\Core;

/**
 * Smoke test for the executor/compiler globals resolution in Core::init().
 *
 * On NTS builds EG/CG are plain extern symbols; on ZTS builds Core resolves them
 * through the TSRM (tsrm_get_ls_cache() + the engine-exported byte offsets, the
 * same fast path the EG()/CG() macros compile to - issue #60). These tests prove
 * the resolved view is the LIVE per-thread globals block, not a stale or
 * misaligned address.
 */
class TsrmGlobalsTest extends TestCase
{
    public function testExecutorGlobalsAreLive(): void
    {
        $functionName = 'zengine_tsrm_probe_' . bin2hex(random_bytes(8));
        eval("function {$functionName}() {}");

        $entry = Core::$executor->functionTable->find($functionName);
        $this->assertNotNull($entry, 'A function defined at runtime must be visible through EG(function_table)');
        $this->assertNotNull(
            Core::$executor->classTable->find(strtolower(self::class)),
            'The running test class must be visible through EG(class_table)',
        );
    }

    public function testTsrmResolvedGlobalsTrackNativeEngineState(): void
    {
        if (!\ZEND_THREAD_SAFE) {
            self::markTestSkipped('TSRM resolution only exists on ZTS builds');
        }

        // A native write into EG(error_reporting) must be visible through the
        // TSRM-resolved view immediately: on a misresolved base this reads back
        // stale heap bytes instead of the live per-thread value
        $original = error_reporting();
        try {
            error_reporting(E_ERROR);
            $this->assertSame(
                E_ERROR,
                Core::$executor->getErrorReporting(),
                'Core::$executor must wrap the same EG block the engine macros mutate',
            );
        } finally {
            error_reporting($original);
        }
        $this->assertSame($original, Core::$executor->getErrorReporting());
    }
}
