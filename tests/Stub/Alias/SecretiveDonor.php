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
 * Donor body for the private Secretive::secret()
 *
 * Public on purpose: the entry keeps its own (private) declaration flags, only the body is
 * adopted, and an unused private method would be a static-analysis finding
 *
 * @method int secretOriginal() the preserved Secretive::secret() body
 */
class SecretiveDonor
{
    public function secret(): int
    {
        return $this->secretOriginal() + 1;
    }
}
