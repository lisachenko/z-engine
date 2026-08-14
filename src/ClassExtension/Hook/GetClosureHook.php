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
use ZEngine\Core;
use ZEngine\Generated\zend_object;
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\ObjectEntry;
use ZEngine\Type\StringEntry;

/**
 * Receiving hook for object-to-closure resolution: $object(...), Closure::fromCallable(),
 * is_callable() - invokable objects without __invoke
 *
 * Memory ownership contract (see docs/long-running.md for the full model):
 *
 *  - The out-parameters are filled exactly like zend_closure_get_closure does it for real
 *    closures: *fptr_ptr borrows the zend_function EMBEDDED in the resolved closure
 *    object, *ce_ptr its called scope, *obj_ptr its bound $this (or NULL). No reference
 *    is handed over - callers that outlive the call addref the closure object themselves
 *    (the VM's ZEND_ACC_CLOSURE handling in zend_init_dynamic_call, closure copying in
 *    zend_create_closure).
 *  - The hook RETAINS the most recently resolved closure: between the trampoline return
 *    and the caller's own addref the resolved closure would otherwise be collectable
 *    while the engine still holds its borrowed func pointer. The retained reference is
 *    replaced on the next resolution and released with the hook; by the time any nested
 *    resolution can run, the engine caller has already secured its own reference.
 *  - proceed() re-invokes the original handler (std resolves __invoke) and materializes
 *    an equivalent PHP closure, or returns null when the engine reports FAILURE (the
 *    object is not invokable).
 *  - The user handler must not let exceptions escape (see issue #50), and must stay
 *    side-effect-free when isCheckOnly() reports a pure callability probe.
 */
final class GetClosureHook extends AbstractHook
{
    protected const HOOK_FIELD = 'get_closure';

    /**
     * Object instance being resolved to a closure
     *
     * @var zend_object Typed view of the engine handle; the runtime value is the raw
     *                  FFI\CData pointer (see stubs/zend-engine-structs.php)
     */
    protected object $object;

    /**
     * Out-parameter: resolved calling scope (zend_class_entry **)
     *
     * A double pointer: the stubs model structs, not pointer-to-pointer handles.
     */
    protected CData $cePtr;

    /**
     * Out-parameter: resolved function (zend_function **)
     *
     * A double pointer: the stubs model structs, not pointer-to-pointer handles.
     */
    protected CData $fptrPtr;

    /**
     * Out-parameter: resolved bound object (zend_object **)
     *
     * A double pointer: the stubs model structs, not pointer-to-pointer handles.
     */
    protected CData $objPtr;

    /**
     * Whether the engine only probes callability (is_callable) without invoking
     */
    protected bool $checkOnly = false;

    /**
     * Keeps the most recently resolved closure alive for the engine's borrowed pointers
     */
    private ?\Closure $resolvedClosure = null; // @phpstan-ignore property.onlyWritten (pure lifetime retention)

    /**
     * typedef zend_result (*zend_object_get_closure_t)(zend_object *obj,
     *     zend_class_entry **ce_ptr, zend_function **fptr_ptr, zend_object **obj_ptr,
     *     bool check_only);
     *
     * @inheritDoc
     */
    #[\Override]
    public function handle(...$rawArguments): int
    {
        /**
         * @var zend_object $object  Narrowed to the stub view at the engine callback boundary
         * @var CData       $cePtr
         * @var CData       $fptrPtr
         * @var CData       $objPtr
         */
        [$object, $cePtr, $fptrPtr, $objPtr, $checkOnly] = $rawArguments;
        // libffi marshals the _Bool argument as an integer scalar
        assert(is_bool($checkOnly) || is_int($checkOnly));
        $this->object    = $object;
        $this->cePtr     = $cePtr;
        $this->fptrPtr   = $fptrPtr;
        $this->objPtr    = $objPtr;
        $this->checkOnly = (bool) $checkOnly;

        $result = ($this->userHandler)($this);
        assert($result instanceof \Closure);

        // Keep the resolved closure alive until the engine caller takes its own reference
        // (or until the next resolution replaces it)
        $this->resolvedClosure = $result;

        $refValue   = new ReflectionValue($result);
        $rawClosure = Core::cast('zend_closure *', $refValue->getRawObject());
        $refValue->release();

        // Mirror zend_closure_get_closure: borrowed embedded function, called scope and
        // bound $this of the resolved closure
        $closureFunc = $rawClosure->func;
        assert($closureFunc instanceof CData);
        $rawFunction = Core::cast('zend_function *', Core::addr($closureFunc));
        // @phpstan-ignore offsetAssign.dimType (writing through engine out-parameter double pointers)
        $this->fptrPtr[0] = $rawFunction;
        // @phpstan-ignore offsetAssign.dimType
        $this->cePtr[0] = $rawClosure->called_scope;

        $closureThis = $rawClosure->this_ptr;
        assert($closureThis instanceof CData);
        $thisValue  = ReflectionValue::fromValueEntry(Core::addr($closureThis));
        $typeMask   = Core::engineConstant('Z_TYPE_MASK');
        $isBoundObj = ($thisValue->getType() & $typeMask) === ReflectionValue::IS_OBJECT;
        // @phpstan-ignore offsetAssign.dimType
        $this->objPtr[0] = $isBoundObj ? $thisValue->getRawObject() : null;

        return Core::SUCCESS;
    }

    /**
     * Returns the object instance being resolved
     */
    public function getObject(): object
    {
        $objectInstance = ObjectEntry::fromCData($this->object)->getNativeValue();

        return $objectInstance;
    }

    /**
     * Whether the engine only probes callability (is_callable) without invoking:
     * the user handler must produce no side effects on this path
     */
    public function isCheckOnly(): bool
    {
        return $this->checkOnly;
    }

    /**
     * Proceeds with the original handler and materializes the engine-resolved closure
     *
     * Returns null when the original handler reports FAILURE (the object is not
     * invokable - e.g. the standard handler found no __invoke method).
     */
    public function proceed(): ?\Closure
    {
        $originalHandler = $this->getOriginalCallable();

        $result = ($originalHandler)($this->object, $this->cePtr, $this->fptrPtr, $this->objPtr, $this->checkOnly);
        if ($result !== Core::SUCCESS) {
            return null;
        }

        // @phpstan-ignore offsetAccess.nonOffsetAccessible (reading engine out-parameter double pointers)
        $rawFunction = $this->fptrPtr[0];
        assert($rawFunction instanceof CData);
        $common = $rawFunction->common;
        assert($common instanceof CData);
        $fnFlags = $common->fn_flags;
        assert(is_int($fnFlags));

        if (($fnFlags & Core::ZEND_ACC_CLOSURE) !== 0) {
            // The resolved function is embedded in a closure object: wrap that object
            // (materializing takes an own reference for the returned PHP value)
            $funcOffset = Core::type('zend_closure')->getStructFieldOffset('func');
            // @phpstan-ignore binaryOp.invalid (FFI char* pointer arithmetic)
            $objectStart = Core::cast('char *', $rawFunction) - $funcOffset;
            assert($objectStart instanceof CData);
            $closureObject = Core::cast('zend_object *', $objectStart);
            $closure       = ObjectEntry::fromCData($closureObject)->getNativeValue();
            assert($closure instanceof \Closure);

            return $closure;
        }

        // A plain method was resolved (the standard handler reports __invoke): rebuild an
        // equivalent closure through the regular callable machinery
        $functionName = $common->function_name;
        assert($functionName instanceof CData);
        $methodName = StringEntry::fromCData($functionName)->getStringValue();

        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        $boundObject = $this->objPtr[0];
        if ($boundObject !== null) {
            assert($boundObject instanceof CData);
            $instance = ObjectEntry::fromCData($boundObject)->getNativeValue();
            $callable = [$instance, $methodName];
            assert(is_callable($callable));

            return \Closure::fromCallable($callable);
        }

        $scope = $common->scope;
        assert($scope instanceof CData);
        $scopeName = $scope->name;
        assert($scopeName instanceof CData);
        $callable = StringEntry::fromCData($scopeName)->getStringValue() . '::' . $methodName;
        assert(is_callable($callable));

        return \Closure::fromCallable($callable);
    }
}
