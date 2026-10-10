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
 * Autoloaded AFTER OverridingLateBase2::m() was redefined; declares a PRIVATE method of the
 * alias name, which the engine marks ZEND_ACC_CHANGED at link time - the reference for what
 * MethodPublisher does to a subclass linked before
 */
class OverridingLateMid extends OverridingLateBase2
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
        return 'late-mid-private';
    }
}
