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
 * Linked BEFORE the preserving redefinition of MatrixEarlyBase::handle(); overrides it and
 * reaches the preserved body through parent::, which calls the alias on this instance
 */
class MatrixEarlyChild extends MatrixEarlyBase
{
    public function handle(): string
    {
        return 'child:' . parent::handle();
    }
}
