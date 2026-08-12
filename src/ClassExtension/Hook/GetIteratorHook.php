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
use Iterator;
use Throwable;
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\ObjectEntry;

/**
 * Receiving hook for engine-level iteration (foreach) over objects of a class
 *
 * Unlike the zend_object_handlers family this hook binds a CLASS-ENTRY-level slot
 * (ce->get_iterator, like create_object/interface_gets_implemented): the engine calls it
 * from FE_RESET (foreach), argument unpacking and every other zend_object_iterator
 * consumer. The user handler returns a userland \Iterator; IteratorBridge wraps it into a
 * native zend_object_iterator whose vtable forwards each step back to that Iterator.
 *
 * Contract decisions (all engine-observable, covered by tests):
 *
 *  - Each started iteration calls the user handler once and gets a FRESH engine iterator,
 *    so nested/concurrent foreach loops over the same or different instances are
 *    independent.
 *  - By-reference iteration (foreach ($obj as &$v)) is NOT bridgeable: get_current_data
 *    would have to expose an engine-writable reference slot behind the userland Iterator,
 *    which it cannot promise. handle() returns no iterator (NULL) WITHOUT touching
 *    EG(exception), which is the engine's own protocol for "no iterator": the VM then
 *    raises the standard "Object of type %s did not create an Iterator" Error. This is the
 *    safest rejection path - z-engine cannot plant an exception from inside an FFI
 *    callback (ext/ffi aborts if EG(exception) is set on callback return, issue #50).
 *  - A Throwable escaping the user handler (or a handler returning a non-Iterator) is
 *    reported as E_USER_WARNING and mapped onto the same NULL protocol.
 *  - uninstall() restores the previous ce->get_iterator (NULL for plain user classes), so
 *    foreach falls back to default property iteration; iterations already in flight keep
 *    their bridged iterator until their loop ends.
 */
class GetIteratorHook extends AbstractHook
{
    protected const HOOK_FIELD = 'get_iterator';

    /**
     * Class entry of the object being iterated
     */
    protected CData $classType;

    /**
     * Object being iterated (zval *)
     */
    protected CData $object;

    /**
     * Whether by-reference iteration was requested
     */
    protected int $byRef;

    /**
     * zend_object_iterator *(*get_iterator)(zend_class_entry *ce, zval *object, int by_ref);
     *
     * @inheritDoc
     * @return \FFI\CData|null
     */
    public function handle(...$rawArguments): ?object
    {
        [$classType, $object, $byRef] = $rawArguments;
        assert($classType instanceof CData && $object instanceof CData && is_int($byRef));
        $this->classType = $classType;
        $this->object    = $object;
        $this->byRef     = $byRef;

        if ($byRef !== 0) {
            // No iterator + no exception: the engine raises the standard Error itself
            return null;
        }

        try {
            $userIterator = ($this->userHandler)($this);
        } catch (Throwable $error) {
            trigger_error(
                'Engine get_iterator handler threw ' . get_class($error) . ": {$error->getMessage()}"
                . ' (exceptions cannot cross the FFI boundary, see issue #50)',
                E_USER_WARNING,
            );

            return null;
        }

        if (!$userIterator instanceof Iterator) {
            trigger_error('Engine get_iterator handler must return an \Iterator instance', E_USER_WARNING);

            return null;
        }

        return IteratorBridge::create($userIterator);
    }

    /**
     * Returns the object instance being iterated
     */
    public function getObject(): object
    {
        $rawObject = ReflectionValue::fromValueEntry($this->object)->getRawObject();

        return ObjectEntry::fromCData($rawObject)->getNativeValue();
    }

    /**
     * Returns true when by-reference iteration (foreach ($obj as &$v)) was requested
     */
    public function isByRefRequested(): bool
    {
        return $this->byRef !== 0;
    }

    /**
     * Proceeds with the original ce->get_iterator handler (internal classes only)
     *
     * @return CData|null zend_object_iterator* produced by the original handler
     */
    public function proceed(): ?object
    {
        $result = ($this->getOriginalCallable())($this->classType, $this->object, $this->byRef);
        assert($result === null || $result instanceof CData);

        return $result;
    }
}
