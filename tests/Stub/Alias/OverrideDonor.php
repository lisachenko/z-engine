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
 * Donor bodies published on OverrideDerived as overrides of inherited methods
 */
class OverrideDonor extends OverrideBase
{
    public function __construct(public string $tag = 'donor')
    {
        parent::__construct('derived-' . $tag);
    }

    public function hello(): string
    {
        return 'derived+' . parent::hello();
    }

    public function own(): string
    {
        return 'donor-own';
    }
}
