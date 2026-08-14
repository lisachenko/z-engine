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
use ZEngine\Generated\zend_object;
use ZEngine\Generated\zend_string;
use ZEngine\Hook\AbstractHook;
use ZEngine\Type\ObjectEntry;
use ZEngine\Type\StringEntry;

/**
 * Abstract object property operational hook
 *
 * The engine hands the raw callback arguments over through an FFI trampoline, so every
 * pointer arrives untyped: each concrete hook narrows them once in handle() onto the
 * fields below, which carry the generated struct-stub views (see AGENTS.md, "Engine
 * structs are typed by generated stub classes").
 */
abstract class AbstractPropertyHook extends AbstractHook
{
    /**
     * Object instance
     *
     * @var zend_object Typed view of the engine handle; the runtime value is the raw
     *                  FFI\CData pointer (see stubs/zend-engine-structs.php)
     */
    protected object $object;

    /**
     * Member name
     *
     * @var zend_string Typed view of the engine handle; the runtime value is the raw
     *                  FFI\CData pointer
     */
    protected object $member;

    /**
     * Internal cache slot (for native callback only)
     *
     * A raw `void **` run-time cache slot: no engine struct and therefore no stub view.
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
