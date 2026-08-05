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

// This file is loaded by opcache-matrix.php in a child process with opcache
// enabled: everything declared here is compiled into opcache shared memory and
// carries ZEND_ACC_IMMUTABLE.

function zengine_shm_function(): string
{
    return 'from-shm';
}

class ZEngineShmClass
{
    public const KIND = 'shm';

    public function greet(): string
    {
        return 'shm-hello';
    }

    public function callsGreet(): string
    {
        // Compiled call site inside a shared-memory body: it must dispatch the
        // redefined method through the writable copy of the class entry
        return 'via:' . $this->greet();
    }
}

/**
 * Target of the addMethod branch of issue #41
 *
 * Kept separate from the redefine/hot-swap targets so every copy-out in the matrix
 * starts from a pristine shared-memory class entry.
 */
class ZEngineShmExtendable
{
    public const KIND = 'extendable';

    public function original(): string
    {
        return 'original';
    }

    /**
     * Dispatches a method that exists only after addMethod() published it
     *
     * The name arrives as an argument: a runtime-published method is invisible to
     * static analysis, and a compiled call site would resolve nothing at all.
     */
    public function callsInjected(string $injectedMethod): string
    {
        $injectedResult = $this->{$injectedMethod}();

        return 'via:' . (is_string($injectedResult) ? $injectedResult : '');
    }
}

/**
 * Target of the hot-swap branch: a plain shared-memory class whose whole body is
 * replaced from source
 */
class ZEngineShmSwappable
{
    public const VERSION = 'v1';

    public function ping(): string
    {
        return 'v1';
    }
}
