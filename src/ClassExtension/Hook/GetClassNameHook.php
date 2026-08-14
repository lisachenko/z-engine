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

namespace ZEngine\ClassExtension\Hook;

use FFI\CData;
use ZEngine\Generated\zend_object;
use ZEngine\Generated\zend_string;
use ZEngine\Hook\AbstractHook;
use ZEngine\Type\ObjectEntry;
use ZEngine\Type\StringEntry;

/**
 * Receiving hook for overriding the class name reported for an object (var_dump, print_r, ...)
 *
 * Memory ownership contract (see docs/long-running.md for the full model):
 *
 *  - handle() mints a fresh zend_string with exactly one reference handed over to the
 *    engine caller, which releases it after use: zend_std_get_class_name returns an owned
 *    copy and every engine caller pairs the call with zend_string_release.
 *  - proceed() receives an owned zend_string from the original handler and releases it
 *    after materializing the PHP string (interned class names are immutable, the release
 *    is a safe no-op for them).
 *  - The user handler must not let exceptions escape: handle() is entered by the engine
 *    through an FFI trampoline with no PHP frame around it to catch them (see issue #50).
 */
final class GetClassNameHook extends AbstractHook
{
    protected const string HOOK_FIELD = 'get_class_name';

    /**
     * Object instance to report the class name for (const zend_object *)
     *
     * @var zend_object Typed view of the engine handle; the runtime value is the raw
     *                  FFI\CData pointer (see stubs/zend-engine-structs.php)
     */
    protected object $object;

    /**
     * typedef zend_string *(*zend_object_get_class_name_t)(const zend_object *object);
     *
     * @inheritDoc
     * @return zend_string
     */
    #[\Override]
    public function handle(...$rawArguments): object
    {
        /** @var zend_object $object Narrowed to the stub view at the engine callback boundary */
        [$object]     = $rawArguments;
        $this->object = $object;

        $result = ($this->userHandler)($this);
        assert(is_string($result));

        // Exactly one owned reference on a fresh string is handed over to the engine caller
        return StringEntry::fromString($result)->transferReferenceOwnership()->getRawValue();
    }

    /**
     * Returns an object instance
     */
    public function getObject(): object
    {
        $objectInstance = ObjectEntry::fromCData($this->object)->getNativeValue();

        return $objectInstance;
    }

    /**
     * Proceeds with the original handler and returns the engine-reported class name
     */
    public function proceed(): string
    {
        $originalHandler = $this->getOriginalCallable();

        $rawName = ($originalHandler)($this->object);
        assert($rawName instanceof CData);

        $nameEntry = StringEntry::fromCData($rawName);
        $className = $nameEntry->getStringValue();
        // The original handler handed over an owned reference (zend_string_copy semantics)
        $nameEntry->releaseReference();

        return $className;
    }
}
