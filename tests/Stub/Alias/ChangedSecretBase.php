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
 * Declares the private m() that ChangedSecretChild inherits into its own table at link time
 * and then overrides with addMethod()
 * (AddMethodChangedFlagTest::testOverrideOfAParentPrivateMethodIsMarkedChangedAndLinksNoPrototype)
 */
class ChangedSecretBase
{
    /**
     * Resolves m() from the base's own scope
     */
    public function viaBase(): string
    {
        return $this->m();
    }

    /**
     * Receiver-dependent body: not foldable by the optimizer at cache time
     */
    private function m(): string
    {
        return 'secret@' . static::class;
    }
}
