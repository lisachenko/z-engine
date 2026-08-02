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

namespace ZEngine\Memory;

use FFI\CData;
use ZEngine\Core;
use ZEngine\EngineExtension\AbstractModule;
use ZEngine\EngineExtension\ModuleLifecycleInterface;
use ZEngine\Reflection\ReflectionExtension;

/**
 * The engine module backing PersistentHeap (engine name "persistent_heap")
 *
 * Two responsibilities:
 *
 *  1. ANCHOR: the module globals hold a single persistent zval slot pointing at the
 *     heap's root registry table. The persistent module registry stores the module
 *     entry (and therefore the globals block) for the whole process, so the registry
 *     address survives request shutdown even though every PHP static dies with the
 *     request. IS_UNDEF in the slot means "no registry minted yet".
 *  2. LIFECYCLE: requestStartup/requestShutdown forward to the heap so it is
 *     operational exactly within the request window. Delivery guarantees and the
 *     ordering against Core::shutdown() are documented in docs/long-running.md
 *     ("Module lifecycle callbacks") and docs/persistent-heap.md; both callbacks are
 *     exception-free by construction (issue #50: FFI callbacks must never throw).
 */
final class PersistentHeapModule extends AbstractModule implements ModuleLifecycleInterface
{
    public static function targetDebug(): bool
    {
        return ZEND_DEBUG_BUILD;
    }

    public static function targetPersistent(): bool
    {
        // The module entry and its globals must survive request shutdown: they are the
        // only cross-request anchor of the heap registry
        return true;
    }

    public static function targetThreadSafe(): bool
    {
        return ZEND_THREAD_SAFE;
    }

    public static function globalType(): string
    {
        // One zval-sized persistent slot: the heap registry anchor
        return 'zval';
    }

    public function moduleStartup(): void
    {
        // Nothing to do: the anchor slot is zero-initialized (IS_UNDEF) at registration
    }

    public function moduleShutdown(): void
    {
        // Best-effort only (docs/long-running.md); the heap needs no MSHUTDOWN work
    }

    public function requestStartup(): void
    {
        PersistentHeap::onRequestStartup();
    }

    public function requestShutdown(): void
    {
        PersistentHeap::onRequestShutdown();
    }

    /**
     * Returns the anchor slot (zval*) inside the persistent module globals
     *
     * Bypasses AbstractModule::getGlobals(), whose cast targets the globals STRUCT type;
     * the anchor is addressed as a zval pointer instead.
     *
     * @todo AbstractModule::getGlobals() cannot cast a raw globals pointer to a
     *       zval-sized type ("attempt to cast to larger type" - FFI casts reinterpret
     *       the 8-byte pointer variable, they do not dereference); once the base class
     *       casts through a pointer type, this bypass can delegate to it.
     */
    public function anchorSlot(): CData
    {
        $rawGlobals = ReflectionExtension::getGlobals();
        if ($rawGlobals === null) {
            throw new PersistentHeapException('The persistent heap module has no globals block');
        }

        return Core::cast('zval *', $rawGlobals);
    }
}
