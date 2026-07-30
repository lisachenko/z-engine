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
    use ReleasableTrait;

    private CData $pointer;

    /**
     * Creates a string entry from the PHP string
     *
     * The entry holds its own reference on the engine string (unless it is interned), so the
     * wrapped pointer stays valid for the whole wrapper lifetime; release()/__destruct drops it.
     */
    public function __construct(string $value)
    {
        // This code is used to extract a Zval for our $value argument and use its internal pointer.
        // The pointer is stored as zend_string* (not as a dereferenced struct), matching every
        // other construction path - the old struct form broke getStringValue() on constructed
        // entries and could not be passed to engine functions expecting zend_string*
        $valueArgument = Core::$executor->getExecutionState()->getArgument(0);
        $this->pointer = $valueArgument->getRawString();
        if (!$this->isInterned()) {
            $this->incrementReferenceCount();
            $this->ownsReference = true;
        }
    }

    /**
     * Creates a string entry from the zend_string structure (borrowed, does not addref)
     *
     * @param CData $stringPointer Pointer to the structure
     */
    public static function fromCData(CData $stringPointer): StringEntry
    {
        /** @var StringEntry $stringEntry */
        $stringEntry          = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $stringEntry->pointer = $stringPointer;

        return $stringEntry;
    }

    /**
     * Mints a fresh, owned request-lifetime zend_string with refcount 1
     *
     * Unlike the constructor (which references the string of the argument zval), the result
     * never aliases caller memory, which makes it safe to hand over to engine sinks via
     * transferReferenceOwnership(). zend_string_concat2 is used because the whole
     * zend_string_init/alloc family is inline-only and not exported by the engine.
     */
    public static function fromString(string $value): StringEntry
    {
        $rawString = Core::call('zend_string_concat2', $value, strlen($value), '', 0);

        $stringEntry                = static::fromCData($rawString);
        $stringEntry->ownsReference = true;

        return $stringEntry;
    }

    /**
     * Mints an owned persistent (malloc-backed) zend_string with refcount 1
     *
     * Persistent strings are required for sinks inside persistent engine structures
     * (internal classes and their members), which the engine releases with the persistent
     * allocator. The struct is built manually because no persistent string constructor is
     * exported; the layout is verified at boot by the engine layout checks.
     */
    public static function persistent(string $value): StringEntry
    {
        $length    = strlen($value);
        $valOffset = Core::type('zend_string')->getStructFieldOffset('val');

        $buffer = Core::new('char[' . ($valOffset + $length + 1) . ']', false, true);
        $string = Core::cast('zend_string *', $buffer);

        $string->gc->refcount     = 1;
        $string->gc->u->type_info = Core::engineConstant('GC_STRING') | Core::engineConstant('GC_PERSISTENT');
        $string->len              = $length;
        Core::memcpy(Core::cast('char *', $buffer) + $valOffset, $value . "\0", $length + 1);
        $string->h = Core::call('zend_string_hash_func', $string);

        $stringEntry                = static::fromCData($string);
        $stringEntry->ownsReference = true;

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
        $entry->release();

        return $realString;
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
     * @inheritDoc
     */
    protected function doRelease(bool $ownsReference, bool $ownsContainer): void
    {
        if ($ownsReference) {
            // Full engine semantics (interned no-op, rc_dtor_func at refcount zero),
            // never an FFI-level free of engine memory
            $this->releaseReference();
        }
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
