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

use FFI\CData;
use ReflectionClass as NativeReflectionClass;
use ZEngine\Core;
use ZEngine\Type\StringEntry;

/**
 * Class ReflectionConstant wraps an engine-level constant (an EG(zend_constants) entry)
 *
 * typedef struct _zend_constant {
 *     zval value;
 *     zend_string *name;
 * } zend_constant;
 *
 * The CONST_* flags and the registering module number are packed into the
 * zval.u2.constant_flags slot of `value`: the low byte holds the flags
 * (engine macro ZEND_CONSTANT_FLAGS) and the upper bits hold the module
 * number (ZEND_CONSTANT_MODULE_NUMBER, PHP_USER_CONSTANT for define()d
 * userland constants). Both accessors below mirror those engine macros.
 *
 * Memory ownership contract: the wrapper is always a BORROWED view over the
 * engine-owned zend_constant structure - reading never changes refcounts.
 * remove() deletes the underlying bucket, after which the engine destructor
 * has released the structure and this wrapper must not be accessed anymore.
 */
class ReflectionConstant
{
    /**
     * Mask of the flags byte inside zval.u2.constant_flags
     *
     * @see zend_constants.h:ZEND_CONSTANT_FLAGS(c) macro
     */
    private const CONSTANT_FLAGS_MASK = 0xFF;

    /**
     * Bit shift of the module number inside zval.u2.constant_flags
     *
     * @see zend_constants.h:ZEND_CONSTANT_MODULE_NUMBER(c) macro
     */
    private const MODULE_NUMBER_SHIFT = 8;

    /**
     * Pointer to the zend_constant structure
     */
    private CData $pointer;

    public function __construct(string $constantName)
    {
        $constantEntry = Core::$executor->constantTable->find($constantName);
        if ($constantEntry === null) {
            throw new \ReflectionException("Constant {$constantName} should be registered in the engine");
        }
        $this->pointer = Core::cast('zend_constant *', $constantEntry->getRawPointer());
    }

    /**
     * Creates a reflection from the zend_constant structure
     *
     * @param CData $constantEntry Pointer to the zend_constant structure
     */
    public static function fromCData(object $constantEntry): ReflectionConstant
    {
        /** @var ReflectionConstant $reflectionConstant */
        $reflectionConstant          = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
        $reflectionConstant->pointer = $constantEntry;

        return $reflectionConstant;
    }

    /**
     * Returns the name of the constant (case-sensitive, as registered in the engine)
     */
    public function getName(): string
    {
        $namePointer = $this->pointer->name;
        \assert($namePointer instanceof CData);

        return StringEntry::fromCData($namePointer)->getStringValue();
    }

    /**
     * Returns a reflection value for this constant (a BORROWED view over the stored zval)
     */
    public function getReflectionValue(): ReflectionValue
    {
        $valueEntry = $this->pointer->value;
        \assert($valueEntry instanceof CData);

        return ReflectionValue::fromValueEntry($valueEntry);
    }

    /**
     * Returns the CONST_* flags byte of this constant
     *
     * @see zend_constants.h:ZEND_CONSTANT_FLAGS(c) macro
     */
    public function getFlags(): int
    {
        return $this->constantFlagsWord() & self::CONSTANT_FLAGS_MASK;
    }

    /**
     * Checks if the constant is persistent (registered by the engine or an extension,
     * surviving request shutdown), as opposed to a define()d userland constant
     */
    public function isPersistent(): bool
    {
        return (bool) ($this->getFlags() & Core::engineConstant('CONST_PERSISTENT'));
    }

    /**
     * Returns the number of the module that registered this constant
     *
     * Userland constants report PHP_USER_CONSTANT (Core::engineConstant('PHP_USER_CONSTANT')).
     *
     * @see zend_constants.h:ZEND_CONSTANT_MODULE_NUMBER(c) macro
     */
    public function getModuleNumber(): int
    {
        return $this->constantFlagsWord() >> self::MODULE_NUMBER_SHIFT;
    }

    /**
     * Removes the constant from the engine constant table, making defined() report false
     *
     * Only define()d userland constants may be removed: persistent and internal (module)
     * constants are refused with a `false` return, because the engine releases them by
     * different rules at module shutdown and other engine structures may point at them.
     *
     * The deletion goes through zend_hash_del: the table destructor (free_zend_constant)
     * releases the value, the name and the zend_constant structure itself, so this wrapper
     * MUST NOT be accessed after a successful removal.
     *
     * @return bool True when the constant was removed, false when removal was refused
     */
    public function remove(): bool
    {
        $isUserConstant = $this->getModuleNumber() === Core::engineConstant('PHP_USER_CONSTANT');
        if ($this->isPersistent() || !$isUserConstant) {
            return false;
        }
        Core::$executor->constantTable->delete($this->getName());

        return true;
    }

    /**
     * Returns the raw zval.u2.constant_flags word of the stored value (flags + module number)
     */
    private function constantFlagsWord(): int
    {
        $valueEntry = $this->pointer->value;
        \assert($valueEntry instanceof CData);
        $reservedSlot = $valueEntry->u2;
        \assert($reservedSlot instanceof CData);
        $constantFlags = $reservedSlot->constant_flags;
        \assert(\is_int($constantFlags));

        return $constantFlags;
    }

    /**
     * Returns a user-friendly representation of internal structure to prevent segfault
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'name'       => $this->getName(),
            'persistent' => $this->isPersistent(),
        ];
    }
}
