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

use ZEngine\Core;
use ZEngine\Generated\zend_object;
use ZEngine\Generated\zval;
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\ObjectEntry;

/**
 * Receiving hook for casting object to another type
 */
final class CastObjectHook extends AbstractHook
{
    protected const string HOOK_FIELD = 'cast_object';

    /**
     * Object instance to perform casting
     *
     * @var zend_object Typed view of the engine handle; the runtime value is the raw
     *                  FFI\CData pointer (see stubs/zend-engine-structs.php)
     */
    protected object $object;

    /**
     * Holds a return value
     *
     * @var zval Typed view of the engine handle; the runtime value is the raw FFI\CData pointer
     */
    protected object $returnValue;

    /**
     * Cast type
     */
    protected int $type;

    /**
     * Status of the last proceed() call within the current handle() invocation, null if none
     *
     * The engine hands cast_object an UNINITIALIZED retval slot; only a successful original
     * handler writes it. Tracking the status is what lets getResult() refuse to read scratch
     * memory and lets handle() propagate a fall-through failure to the engine caller.
     */
    private ?int $lastProceedStatus = null;

    /**
     * typedef int (*zend_object_cast_t)(zend_object *readobj, zval *retval, int type);
     *
     * @inheritDoc
     */
    #[\Override]
    public function handle(...$rawArguments): int
    {
        /**
         * @var zend_object $object Narrowed to the stub views at the engine callback boundary
         * @var zval        $returnValue
         * @var int         $type
         */
        [$object, $returnValue, $type] = $rawArguments;
        $this->object                  = $object;
        $this->returnValue             = $returnValue;
        $this->type                    = $type;
        $this->lastProceedStatus       = null;

        $result = ($this->userHandler)($this);
        if ($result === null && $this->lastProceedFailed()) {
            // The user handler fell through to the original handler and that handler could not
            // produce a value. Propagate the failure so the engine caller applies its own
            // default behaviour (diagnostic and substitute value for numeric casts, engine
            // Error for string casts) instead of silently installing NULL as the cast result
            return Core::FAILURE;
        }

        // The retval slot is uninitialized scratch memory provided by the engine caller,
        // so there is no previous value to release in it
        ReflectionValue::fromValueEntry($this->returnValue)->initializeNativeValue($result);

        return Core::SUCCESS;
    }

    /**
     * Returns the cast type
     *
     * @see ReflectionValue class constants, like ReflectionValue::IS_DOUBLE
     */
    public function getCastType(): int
    {
        return $this->type;
    }

    /**
     * Returns the cast type as a named case, or null for an id unknown to this PHP line
     *
     * Prefer this over comparing getCastType() against numeric constants: the cast-only type ids
     * have shifted between PHP minors before, and the enum is guarded against the generated
     * engine ground truth.
     */
    public function getCastTypeEnum(): ?CastType
    {
        return CastType::tryFrom($this->type);
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
     * Whether the user handler fell through to the original handler and that handler failed
     *
     * A method rather than an inline comparison on purpose: the property is mutated by
     * proceed() from inside the userHandler closure invocation, a side effect static analysis
     * cannot see through — inline, the null assignment in handle() would narrow the comparison
     * to a compile-time constant.
     */
    private function lastProceedFailed(): bool
    {
        return $this->lastProceedStatus === Core::FAILURE;
    }

    /**
     * Returns result of casting from a successful call to proceed(), null otherwise
     *
     * The retval slot is written only by a successful proceed(): reading it before one (or after
     * a failed one) would dereference uninitialized scratch memory, so null is returned instead.
     * Combined with handle() this makes the naive fall-through — `$hook->proceed(); return
     * $hook->getResult();` — behave exactly like an uninstalled handler for every cast type.
     */
    public function getResult(): mixed
    {
        if ($this->lastProceedStatus !== Core::SUCCESS) {
            return null;
        }
        ReflectionValue::fromValueEntry($this->returnValue)->getNativeValue($result);

        return $result;
    }

    /**
     * Proceeds with object casting
     *
     * @return int Core::SUCCESS when the original handler produced a value in the retval slot,
     *             Core::FAILURE when it could not (numeric casts on plain objects, for example)
     */
    public function proceed(): int
    {
        $originalHandler = $this->getOriginalCallable();

        $status = ($originalHandler)($this->object, $this->returnValue, $this->type);
        assert(is_int($status));
        $this->lastProceedStatus = $status;

        return $this->lastProceedStatus;
    }
}
