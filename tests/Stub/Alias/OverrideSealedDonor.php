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
 * Donor whose sealed() must be refused as an override of the final OverrideBase::sealed()
 *
 * Deliberately no parent class: extending OverrideBase with a sealed() method would be a
 * compile-time error already.
 */
class OverrideSealedDonor
{
    public function sealed(): string
    {
        return 'unsealed';
    }
}
