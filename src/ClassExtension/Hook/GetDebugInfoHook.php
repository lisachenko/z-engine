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
use ZEngine\Hook\AbstractHook;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\HashTable;
use ZEngine\Type\ObjectEntry;

/**
 * Receiving hook for providing custom debug info (what var_dump() and debuggers see)
 *
 * Memory ownership contract (see docs/long-running.md for the full model):
 *
 *  - handle() converts the PHP array returned by the user handler into a HashTable* and
 *    reports it with *is_temp = 1: exactly one payload reference is handed over to the
 *    engine caller, which releases it after dumping (zend_release_properties). The
 *    temporary zval container is freed here; nothing on the PHP side may release the
 *    handed-over reference again.
 *  - proceed() materializes the table produced by the original get_debug_info handler as
 *    a fresh PHP array of plain values (IS_INDIRECT property slots are followed, every
 *    value gets its own reference). When the original handler reported its table as
 *    temporary, the only reference it handed over is released here with full engine
 *    semantics (mirroring zend_release_properties); a non-temporary table stays borrowed
 *    from the object and is left untouched.
 *  - The user handler must not let exceptions escape: handle() is entered by the engine
 *    through an FFI trampoline with no PHP frame around it to catch them (see issue #50).
 */
class GetDebugInfoHook extends AbstractHook
{
    protected const HOOK_FIELD = 'get_debug_info';

    /**
     * Object instance to provide debug info for
     */
    protected CData $object;

    /**
     * Engine-provided output flag (int *): whether the returned table is temporary
     */
    protected CData $isTemp;

    /**
     * typedef HashTable *(*zend_object_get_debug_info_t)(zend_object *object, int *is_temp);
     *
     * @inheritDoc
     * @return \FFI\CData
     */
    public function handle(...$rawArguments): object
    {
        [$object, $isTemp] = $rawArguments;
        assert($object instanceof CData && $isTemp instanceof CData);
        $this->object = $object;
        $this->isTemp = $isTemp;

        $result   = ($this->userHandler)($this);
        $refValue = new ReflectionValue($result);
        $rawArray = $refValue->getRawArray();

        // With *is_temp = 1 the engine caller releases the returned table itself, so
        // exactly one payload reference is handed over; the temporary zval container
        // is freed right away
        $this->isTemp[0] = 1;
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
     * Proceeds with the default engine debug info from the original handler
     *
     * @return array<array-key, mixed>
     */
    public function proceed(): array
    {
        if (!$this->hasOriginalHandler()) {
            throw new \LogicException('Original handler is not available');
        }

        $originalHandler = $this->getOriginalCallable();

        $object = $this->object;
        $scope  = $object->ce;
        assert($scope instanceof CData);
        // Use an own is_temp output slot: the engine-provided one must stay untouched
        // until handle() reports its final verdict
        $isTemp = Core::new('int');

        // As we will play with EG(fake_scope), we won't be able to access private or protected members
        $previousScope = Core::$executor->setFakeScope($scope);
        $rawArray      = ($originalHandler)($object, Core::addr($isTemp));
        Core::$executor->setFakeScope($previousScope);

        if ($rawArray === null) {
            return [];
        }
        assert($rawArray instanceof CData);

        $table     = HashTable::fromCData($rawArray);
        $debugInfo = [];
        foreach ($table as $key => $refValue) {
            // Property tables store declared properties as IS_INDIRECT slots pointing into
            // the object: follow them (skipping uninitialized ones), because a userland
            // array must contain only plain values
            if ($refValue->getType() === ReflectionValue::IS_INDIRECT) {
                $refValue = $refValue->getIndirectValue();
                if ($refValue->getType() === ReflectionValue::IS_UNDEF) {
                    continue;
                }
            }
            // Each value is materialized with its own reference owned by the built array
            $refValue->getNativeValue($value);
            $debugInfo[$key] = $value;
        }

        // A temporary table comes with the only reference handed over to us: release it
        // exactly like zend_release_properties() would (immutable tables stay untouched)
        if ($isTemp->cdata === 1) {
            $table->releaseReference();
        }

        return $debugInfo;
    }
}
