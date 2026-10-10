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
 * Method donor for CacheFakeMethodTarget::greet(): function calls and a property read, so the
 * body owns several inline-cache slots in the run-time cache minted for the entry
 */
class CacheFakeMethodDonor
{
    public string $name = 'donor';

    public function greet(string $who): string
    {
        return 'donor:' . strtoupper($who) . ' from ' . $this->name . str_repeat('.', 2);
    }
}
