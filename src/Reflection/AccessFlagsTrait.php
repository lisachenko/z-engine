<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\Reflection;

use ZEngine\Core;

/**
 * Visibility and flag mutation shared by every class member reflection
 *
 * Methods, properties and class constants all carry their access flags in a ZEND_ACC_*
 * bit field, only in a different engine structure each (zend_function.common.fn_flags,
 * zend_property_info.flags, zend_class_constant.value.u2.constant_flags). The bit
 * arithmetic - clear the ZEND_ACC_PPP_MASK visibility bits, then set exactly one of them,
 * or toggle a single flag - is identical in all three, so it lives here once and the
 * owning class only says WHERE its flags are, through replaceAccessFlags().
 *
 * @internal
 */
trait AccessFlagsTrait
{
    /**
     * Declares this member as public
     */
    public function setPublic(): void
    {
        $this->replaceAccessFlags(Core::ZEND_ACC_PPP_MASK, Core::ZEND_ACC_PUBLIC);
    }

    /**
     * Declares this member as protected
     */
    public function setProtected(): void
    {
        $this->replaceAccessFlags(Core::ZEND_ACC_PPP_MASK, Core::ZEND_ACC_PROTECTED);
    }

    /**
     * Declares this member as private
     */
    public function setPrivate(): void
    {
        $this->replaceAccessFlags(Core::ZEND_ACC_PPP_MASK, Core::ZEND_ACC_PRIVATE);
    }

    /**
     * Turns exactly one access flag of this member on or off
     */
    protected function setAccessFlag(int $mask, bool $isEnabled): void
    {
        $this->replaceAccessFlags($isEnabled ? 0 : $mask, $isEnabled ? $mask : 0);
    }

    /**
     * Clears the given flags and sets the given ones, in a single write to the engine field
     *
     * Implemented by the owning reflection, which is the only place that knows which
     * structure field holds the flags (AGENTS.md: the field pokes stay inside the owner).
     *
     * @param int $clearMask Flags to clear before applying $setMask
     * @param int $setMask   Flags to set
     */
    abstract protected function replaceAccessFlags(int $clearMask, int $setMask): void;
}
