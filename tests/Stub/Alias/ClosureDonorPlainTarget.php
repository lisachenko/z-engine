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
 * Takes a plain compiled method (ClosureDonorPlainDonor::m) as its method donor: the control
 * showing that only a closure body makes a method donor's statics table get duplicated -
 * owned by RedefineClosureBackedDonorTest::testPlainCompiledMethodDonorKeepsSharingItsDefaultsTable
 */
class ClosureDonorPlainTarget
{
    public function m(): int
    {
        return -1;
    }
}
