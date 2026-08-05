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
}
