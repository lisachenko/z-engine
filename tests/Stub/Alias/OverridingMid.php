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
 * Linked BEFORE OverridingBase2::m() is redefined; declares a PRIVATE method of the alias
 * name itself, which must stay its own and become a redeclaration (ZEND_ACC_CHANGED) of the
 * alias the base publishes
 */
class OverridingMid extends OverridingBase2
{
    /**
     * Resolves mOriginal() from this class's own scope
     */
    public function ownAlias(): string
    {
        return $this->mOriginal();
    }

    private function mOriginal(): string
    {
        return 'mid-private';
    }
}
