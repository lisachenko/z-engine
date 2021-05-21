<?php
/**
 * Z-Engine framework
 *
 * @copyright Copyright 2020, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\ClassExtension\Hook;

use FFI\CData;
use ZEngine\Hook\AbstractHook;
use ZEngine\Type\ObjectEntry;
use ZEngine\Type\StringEntry;

/**
 * Abstract object property operational hook
 */
abstract class AbstractPropertyHook extends AbstractHook
{
    /**
     * Object instance
     */
    protected CData $object;

    /**
     * Member name
     */
    protected CData $member;

    /**
     * Internal cache slot (for native callback only)
     */
    protected ?CData $cacheSlot;

    /**
     * Returns an object instance
     */
    public function getObject(): object
    {
        $objectInstance = ObjectEntry::fromCData($this->object)->getNativeValue();

        return $objectInstance;
    }

    /**
     * Returns a member name
     */
    public function getMemberName(): string
    {
        $memberName = StringEntry::fromCData($this->member)->getStringValue();

        return $memberName;
    }
}
