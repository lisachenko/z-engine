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
use ZEngine\Generated\zend_function;
use ZEngine\Generated\zend_internal_function;
use ZEngine\Generated\zend_string;
use ZEngine\Generated\zval;
use ZEngine\Reflection\ReflectionMethod;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\ObjectEntry;
use ZEngine\Type\StringEntry;

/**
 * Receiving hook for method resolution ($object->method(...)) - true method-call
 * interception without __call limitations, working for defined methods too
 *
 * Memory ownership contract (see docs/long-running.md for the full model):
 *
 *  - The engine expects a BORROWED zend_function*: every returned pointer either comes
 *    from a class method table (owned by the class entry) or round-trips the exact
 *    pointer of the original handler - see AbstractMethodResolutionHook.
 *  - Returning null from the user handler makes the VM raise the standard
 *    "Call to undefined method" Error (unless the handler left an exception pending).
 *
 * Engine caller semantics worth knowing:
 *
 *  - Inline caches: for compile-time constant method names the VM caches the resolved
 *    user function per (call site, class) pair, so this hook resolves such a call site
 *    ONCE and later iterations dispatch straight to the cached function. Dynamic names
 *    ($object->$name()) bypass the cache and resolve through the hook on every call.
 *  - Name case: the engine passes the method name exactly as written at the call site,
 *    plus a pre-lowercased key for constant names (getKey()); dynamic-name calls carry
 *    no key and getLowercasedMethodName() falls back to lowercasing in userland.
 *  - proceed() results backed by an engine __call trampoline MUST be returned to the
 *    engine (the identity round-trip reuses the raw pointer): the VM releases the
 *    trampoline after the call, but a discarded trampoline is never invoked and leaks
 *    its method-name reference.
 *  - The user handler must not let exceptions escape (see issue #50).
 */
final class GetMethodHook extends AbstractMethodResolutionHook
{
    protected const HOOK_FIELD = 'get_method';

    /**
     * Double pointer to the object the method is resolved on (zend_object **)
     *
     * A double pointer: the stubs model structs, not pointer-to-pointer handles.
     */
    protected CData $objectPtr;

    /**
     * Method name exactly as written at the call site (zend_string *)
     *
     * @var zend_string Typed view of the engine handle; the runtime value is the raw
     *                  FFI\CData pointer (see stubs/zend-engine-structs.php)
     */
    protected object $methodName;

    /**
     * Pre-lowercased name key for constant call sites (const zval *), NULL for dynamic names
     *
     * @var zval|null Typed view of the engine handle; the runtime value is the raw FFI\CData pointer
     */
    protected ?object $key = null;

    /**
     * typedef zend_function *(*zend_object_get_method_t)(zend_object **object,
     *     zend_string *method, const zval *key);
     *
     * @inheritDoc
     * @return zend_function|zend_internal_function|null
     */
    #[\Override]
    public function handle(...$rawArguments): ?object
    {
        /**
         * @var CData       $objectPtr  Narrowed to the stub views at the engine callback boundary
         * @var zend_string $methodName
         * @var zval|null   $key
         */
        [$objectPtr, $methodName, $key] = $rawArguments;
        $this->objectPtr                = $objectPtr;
        $this->methodName               = $methodName;
        $this->key                      = $key;

        $result = ($this->userHandler)($this);
        if ($result === null) {
            // NULL without a pending exception raises "Call to undefined method"
            return null;
        }
        assert($result instanceof \ReflectionMethod);

        return $this->resolveRawFunction($result);
    }

    /**
     * Returns the object instance the method is being resolved on
     */
    public function getObject(): object
    {
        // @phpstan-ignore offsetAccess.nonOffsetAccessible (dereferencing the zend_object** parameter)
        $object = $this->objectPtr[0];
        assert($object instanceof CData);
        $objectInstance = ObjectEntry::fromCData($object)->getNativeValue();

        return $objectInstance;
    }

    /**
     * Returns the method name exactly as written at the call site (case preserved)
     */
    public function getMethodName(): string
    {
        return StringEntry::fromCData($this->methodName)->getStringValue();
    }

    /**
     * Returns the pre-lowercased name key for constant call sites, null for dynamic names
     */
    public function getKey(): ?string
    {
        if ($this->key === null) {
            return null;
        }

        return StringEntry::fromCData(ReflectionValue::fromValueEntry($this->key)->getRawString())->getStringValue();
    }

    /**
     * Returns the lowercased method name (engine-provided key when present, computed otherwise)
     */
    public function getLowercasedMethodName(): string
    {
        return $this->getKey() ?? strtolower($this->getMethodName());
    }

    /**
     * Proceeds with the original handler and returns the engine-resolved method
     *
     * Returns null when the engine finds no method (the caller then raises the standard
     * undefined-method Error). A resolution that goes through __call yields an
     * engine-managed trampoline: such a wrapper is low-level only and must be returned
     * from the user handler (not discarded) so the engine can release it after the call.
     */
    public function proceed(): ?ReflectionMethod
    {
        $originalHandler = $this->getOriginalCallable();

        $rawFunction = ($originalHandler)($this->objectPtr, $this->methodName, $this->key);
        if ($rawFunction === null) {
            return null;
        }
        assert($rawFunction instanceof CData);

        return $this->wrapRawFunction($rawFunction);
    }
}
