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
use ZEngine\Core;
use ZEngine\Generated\zend_object;
use ZEngine\Generated\zend_object_handlers;
use ZEngine\Memory\Allocator;
use ZEngine\Memory\EngineAllocator;
use ZEngine\Reflection\ReflectionClass;

/**
 * Mints malloc-backed zend_object clones that can outlive the request
 *
 * A persistent clone is a byte copy of a live object with its GC header rewritten so no
 * engine path ever reclaims it:
 *
 *  - refcount pinned at PIN_BASELINE: request-time addref/delref churn never reaches
 *    zero, so rc_dtor_func/zend_object_release are never triggered;
 *  - GC_NOT_COLLECTABLE: GC_TYPE_INFO no longer equals GC_OBJECT, so the cycle
 *    collector neither buffers the object as a possible root nor writes gc_info bits
 *    into the persistent header;
 *  - GC_PERSISTENT: z-engine wrappers recognize the payload as malloc-backed and skip
 *    engine destruction on release;
 *  - IS_OBJ_DESTRUCTOR_CALLED in extra_flags: shutdown destructor passes over the
 *    object store skip it (__destruct never runs for persistent clones, by design);
 *  - handlers rewired to the engine's std_object_handlers global: the only handlers
 *    block whose address is stable for the whole process lifetime. Callers must reject
 *    source objects using any other handlers block (internal classes, hooked classes) -
 *    a pointer to a request-lifetime handlers block would dangle on the next request.
 *
 * Deliberately does NOT go through zend_object_std_init: std_init registers the object
 * in the request-scoped EG(objects_store) and writes request-normal GC flags. The clone
 * leaves the copied handle in place; it is only valid after the caller re-registers the
 * object for the current request via ObjectStore::put().
 *
 * The inline properties_table zvals are byte copies of the source: the caller (persister)
 * owns converting every refcounted slot into a persistent/immutable payload - a byte
 * copy of a request-allocated payload pointer would dangle after the request ends.
 *
 * Memory ownership: clones are immortal-by-design (docs/long-running.md); nothing frees
 * them before process end.
 */
final class PersistentObjectFactory
{
    /**
     * Refcount baseline for persistent objects: high enough that request-time release
     * churn can never reach zero, low enough to stay far from the uint32 range
     */
    public const int PIN_BASELINE = 1 << 29;

    /**
     * Creates a persistent byte-clone of a live zend_object
     *
     * @param CData|zend_object $sourceObject zend_object* to clone (must use std_object_handlers)
     * @param Allocator|null    $allocator    Source of the clone's memory; the default is
     *                                        z-engine's tracked malloc-backed allocator, ie
     *                                        exactly the process-heap block this factory has
     *                                        always minted. A foreign allocator (a fork-shared
     *                                        arena, say) puts the clone into ITS memory, and
     *                                        the caller releases it the same way it does the
     *                                        rest of that region.
     *
     * @return zend_object zend_object* in persistent memory, not yet registered in the store
     */
    public static function persistentClone(object $sourceObject, ?Allocator $allocator = null): object
    {
        /** @var zend_object $source Narrowed to the stub view at the owning boundary */
        $source      = $sourceObject;
        $sourceClass = $source->ce;
        // Engine invariant: every live object carries its class entry
        assert($sourceClass !== null);
        $totalSize = ReflectionClass::getObjectSize($sourceClass);
        $allocator ??= EngineAllocator::trackedPersistent();
        $object = Core::pointerAtAddress(
            zend_object::class,
            $allocator->allocate($totalSize, Allocator::ENGINE_STRUCT_ALIGNMENT),
        );

        Core::memcpy($object, Core::cast('char *', $sourceObject), $totalSize);

        $object->gc->refcount     = self::PIN_BASELINE;
        $object->gc->u->type_info = Core::engineConstant('GC_OBJECT')
            | Core::engineConstant('GC_NOT_COLLECTABLE')
            | Core::engineConstant('GC_PERSISTENT');
        // Both shutdown passes over the object store must skip persistent clones: the
        // destructor pass honors IS_OBJ_DESTRUCTOR_CALLED, the free-storage pass honors
        // IS_OBJ_FREE_CALLED. Callers detach the object before teardown anyway - these
        // flags are the belt-and-braces layer for buckets that leak past a missed detach.
        $object->extra_flags |= Core::engineConstant('IS_OBJ_DESTRUCTOR_CALLED')
            | Core::engineConstant('IS_OBJ_FREE_CALLED');
        $object->handlers   = Core::cast(zend_object_handlers::class, Core::addr(Core::getStandardObjectHandlers()));
        $object->properties = null;

        return $object;
    }

    /**
     * Checks that an object uses the engine's std_object_handlers block, the only
     * handlers pointer that stays valid across requests
     *
     * @param CData|zend_object $object
     */
    public static function usesStandardHandlers(object $object): bool
    {
        /** @var zend_object $entry Narrowed to the stub view at the owning boundary */
        $entry    = $object;
        $handlers = $entry->handlers;
        // Engine invariant: every live object carries a handlers block
        assert($handlers !== null);

        return Core::addressOf($handlers) === Core::addressOf(Core::addr(Core::getStandardObjectHandlers()));
    }
}
