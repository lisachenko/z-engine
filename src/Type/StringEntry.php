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

namespace ZEngine\Type;

use FFI\CData;
use ReflectionClass;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionValue;

/**
 * This class wraps PHP's zend_string structure and provide an API for working with it
 *
 * struct _zend_string {
 *   zend_refcounted_h gc;
 *   zend_ulong        h;                // hash value
 *   size_t            len;
 *   char              val[1];
 * };
 */
class StringEntry implements ReferenceCountedInterface
{
    use ReferenceCountedTrait;

    private CData $pointer;

    /**
     * Creates a string entry from the PHP string
     */
    public function __construct(string $value)
    {
        // This code is used to extract a Zval for our $value argument and use its internal pointer
        $valueArgument = Core::$executor->getExecutionState()->getArgument(0);
        $this->pointer = $valueArgument->getRawString()[0];
    }

    /**
     * Creates a string entry from the zend_string structure
     *
     * @param CData $stringPointer Pointer to the structure
     */
    public static function fromCData(CData $stringPointer): StringEntry
    {
        /** @var StringEntry $stringEntry */
        $stringEntry = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $stringEntry->pointer = $stringPointer;

        return $stringEntry;
    }

    /**
     * Returns raw C value entry
     */
    public function getRawValue(): ?CData
    {
        return $this->pointer;
    }

    /**
     * Returns a hash for given string
     */
    public function getHash(): int
    {
        return $this->pointer->h;
    }

    /**
     * Returns a string length
     */
    public function getLength(): int
    {
        return $this->pointer->len;
    }

    /**
     * Returns a PHP representation of engine string
     */
    public function getStringValue(): string
    {
        $entry = ReflectionValue::newEntry(ReflectionValue::IS_STRING, $this->pointer[0]);
        $entry->getNativeValue($realString);

        // TODO: Incapsulate memory management into ReflectionValue->release() method
        Core::free($entry->getRawValue());

        return $realString;
    }

    /**
     * This methods releases a string entry
     *
     * @see zend_string.h:zend_string_release function
     */
    public function release(): void
    {
        if (!$this->isInterned()) {
            if ($this->decrementReferenceCount() === 0) {
                Core::free($this->pointer);
            }
        }
    }

    /**
     * Creates a copy of string value
     *
     * @see zend_string.h::zend_string_copy function
     *
     * @return self
     */
    public function copy(): self
    {
        if (!$this->isInterned()) {
            $this->incrementReferenceCount();
        }

        return $this;
    }

    /**
     * Alias to check if this string is interned (aka immutable)
     *
     * @return bool
     */
    public function isInterned(): bool
    {
        return $this->isImmutable();
    }

    /**
     * This method returns a dumpable representation of internal value to prevent segfault
     */
    public function __debugInfo(): array
    {
        return [
            'value'    => $this->getStringValue(),
            'length'   => $this->getLength(),
            'refcount' => $this->getReferenceCount(),
            'hash'     => $this->getHash(),
        ];
    }

    /**
     * This method should return an instance of zend_refcounted_h
     */
    protected function getGC(): CData
    {
        return $this->pointer->gc;
    }
}
