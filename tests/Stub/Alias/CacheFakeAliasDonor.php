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
 * Method donor for CacheFakeAliasTarget::greet()
 */
class CacheFakeAliasDonor
{
    public string $name = 'donor';

    public function greet(string $who): string
    {
        return 'donor:' . $who . ' from ' . $this->name;
    }
}
