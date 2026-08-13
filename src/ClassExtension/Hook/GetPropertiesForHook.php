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
use ZEngine\Core;
use ZEngine\Generated\zend_array;
use ZEngine\Generated\zend_object;
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\ObjectEntry;

/**
 * Receiving hook for casting to array, debugging, etc
 */
final class GetPropertiesForHook extends AbstractHook
{
    protected const HOOK_FIELD = 'get_properties_for';

    /**
     * Object instance
     *
     * @var zend_object Typed view of the engine handle; the runtime value is the raw
     *                  FFI\CData pointer (see stubs/zend-engine-structs.php)
     */
    protected object $object;

    /**
     * Calling reason
     *
     * @see zend_prop_purpose enumeration
     */
    protected int $purpose;

    /**
     * zend_array *(*zend_object_get_properties_for_t)(zend_object *object, zend_prop_purpose purpose);
     *
     * @inheritDoc
     * @return zend_array
     */
    public function handle(...$rawArguments): object
    {
        /**
         * @var zend_object $object Narrowed to the stub view at the engine callback boundary
         * @var int         $purpose
         */
        [$object, $purpose] = $rawArguments;
        $this->object       = $object;
        $this->purpose      = $purpose;

        $result   = ($this->userHandler)($this);
        $refValue = new ReflectionValue($result);
        $rawArray = $refValue->getRawArray();

        // The engine caller releases the returned hashtable (zend_release_properties), so
        // exactly one reference is handed over; the temporary container itself is freed here
        $refValue->transferReferenceOwnership();
        $refValue->release();

        return $rawArray;
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
     * Returns the purpose
     */
    public function getPurpose(): int
    {
        return $this->purpose;
    }

    /**
     * Returns the purpose as a named case, or null for a value unknown to this PHP line
     */
    public function getPurposeEnum(): ?PropertyPurpose
    {
        return PropertyPurpose::tryFrom($this->purpose);
    }

    /**
     * Proceeds with default handler
     *
     * @return \FFI\CData|null zend_array* produced by the original handler, NULL when it
     *                         reported no dedicated table for this purpose
     */
    public function proceed(): ?object
    {
        // As we will play with EG(fake_scope), we won't be able to access private or protected members, need to unpack
        $originalHandler = $this->getOriginalCallable();

        $object  = $this->object;
        $purpose = $this->purpose;

        $rawArray = Core::$executor->withFakeScope(
            $object->ce,
            static fn() => ($originalHandler)($object, $purpose),
        );
        assert($rawArray === null || $rawArray instanceof CData);

        return $rawArray;
    }
}
