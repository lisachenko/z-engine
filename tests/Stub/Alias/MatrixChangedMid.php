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

namespace ZEngine\Stub\Alias;

/**
 * Redeclares the private MatrixChangedRoot::m(): the engine marks this m() ZEND_ACC_CHANGED
 */
class MatrixChangedMid extends MatrixChangedRoot
{
    public function m(): string
    {
        return 'mid';
    }
}
