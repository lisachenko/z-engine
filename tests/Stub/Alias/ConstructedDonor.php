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
 * Donor constructor: delegates to the preserved original constructor
 *
 * @method void __constructOriginal(string $name) the preserved Constructed::__construct() body
 */
class ConstructedDonor
{
    public function __construct(public string $name = 'donor')
    {
        $this->__constructOriginal(strtoupper($name));
    }
}
