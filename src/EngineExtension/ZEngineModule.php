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

namespace ZEngine\EngineExtension;

use FFI\CData;
use ZEngine\Core;
use ZEngine\Memory\PersistentHeap;
use ZEngine\Memory\PersistentHeapException;
use ZEngine\Reflection\ReflectionExtension;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\PersistentHashTable;

/**
 * THE framework-wide engine module (engine name "zengine"), one per process
 *
 * Everything z-engine itself needs to survive request shutdown anchors here - today
 * that is the persistent heap; future subsystems join this module instead of minting
 * their own entries. Registered explicitly during bootstrap:
 *
 * ```php
 * Core::init();
 * ExtensionManager::register(new ZEngineModule());
 * $heap = PersistentHeap::global();   // = ExtensionManager::get(ZEngineModule::class)->heap()
 * ```
 *
 * Responsibilities:
 *
 *  - ANCHOR: the module globals hold one persistent zval slot pointing at the heap's
 *    root registry table. Since PHP 8.4 the persistent module registry stores the
 *    entry (and therefore the globals block) for the whole process, so the registry
 *    address survives request shutdown even though every PHP static dies with the
 *    request. IS_UNDEF in the slot means "no registry minted yet".
 *  - LIFECYCLE: requestStartup/requestShutdown forward to the heap so it is operational
 *    exactly within the request window (ordering vs. Core::shutdown() documented in
 *    docs/long-running.md and docs/persistent-heap.md); both callbacks are
 *    exception-free by construction (issue #50: FFI callbacks must never throw).
 *  - DIAGNOSTICS: phpinfo() renders the module section with live heap statistics
 *    through the standard info_func machinery (ModuleInfoInterface).
 *
 * The module depends on ext/ffi - the engine refuses to start it when FFI is absent,
 * which is exactly the environment z-engine cannot run in anyway.
 *
 * Public-API purity (AGENTS.md): no method of this module returns CData - the anchor
 * slot and the registry recovery are internal knowledge; consumers only ever see the
 * PersistentHeap facade.
 */
final class ZEngineModule extends AbstractModule implements ModuleLifecycleInterface, ModuleInfoInterface
{
    /**
     * The per-request heap facade over the persistent registry (rebuilt lazily from the
     * anchor after every request shutdown)
     */
    private ?PersistentHeap $heap = null;

    public static function targetDebug(): bool
    {
        return ZEND_DEBUG_BUILD;
    }

    public static function targetPersistent(): bool
    {
        // The module entry and its globals must survive request shutdown: they are the
        // only cross-request anchor of the persistent heap registry
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

    /**
     * @inheritDoc
     * @return list<ModuleDependency>
     */
    public function getModuleDependencies(): array
    {
        // The whole framework runs through the FFI bridge
        return [ModuleDependency::required('ffi')];
    }

    public function moduleStartup(): void
    {
        // Nothing to do: the anchor slot is zero-initialized (IS_UNDEF) at registration
    }

    public function moduleShutdown(): void
    {
        // Best-effort only (docs/long-running.md); no MSHUTDOWN work is needed
    }

    public function requestStartup(): void
    {
        $this->heap?->onRequestStartup();
    }

    public function requestShutdown(): void
    {
        $this->heap?->onRequestShutdown();
        // The next request must re-attach from the anchor instead of trusting PHP state
        $this->heap = null;
    }

    /**
     * Returns the persistent heap of this process, re-attaching it from the anchor
     *
     * The registry table is recovered from (or minted into) the module-globals anchor
     * and INJECTED into the heap - the heap itself never creates its own storage.
     */
    public function heap(): PersistentHeap
    {
        return $this->heap ??= new PersistentHeap($this->recoverHeapRegistry(), $this);
    }

    /**
     * @inheritDoc
     * @return array<string, scalar>
     */
    public function getDisplayInfo(): array
    {
        $rows = [
            'Z-Engine support' => 'enabled',
            'Persistent heap'  => 'not initialized',
        ];
        if ($this->heap !== null) {
            try {
                $stats = $this->heap->stats();

                $rows['Persistent heap']         = 'active';
                $rows['Persistent heap keys']    = $stats['keys'];
                $rows['Persistent heap objects'] = $stats['objects'];
                $rows['Persistent heap strings'] = $stats['strings'];
                $rows['Persistent heap arrays']  = $stats['arrays'];
                $rows['Persistent heap bytes']   = $stats['bytes'];
            } catch (PersistentHeapException) {
                // Inert (request shutdown) or destroyed: report instead of throwing
                // across the info_func FFI boundary (issue #50)
                $rows['Persistent heap'] = 'inert';
            }
        }

        return $rows;
    }

    /**
     * Clears the anchor after PersistentHeap::destroy(): the next heap() call mints a
     * fresh registry
     *
     * @internal called by PersistentHeap::destroy()
     */
    public function onHeapDestroyed(): void
    {
        $anchorTyped = $this->anchorSlot()->u1;
        assert($anchorTyped instanceof CData);
        $anchorTyped->type_info = ReflectionValue::IS_UNDEF;

        $this->heap = null;
    }

    /**
     * Recovers the heap registry from the anchor slot, minting it on first use
     */
    private function recoverHeapRegistry(): PersistentHashTable
    {
        $anchor      = $this->anchorSlot();
        $anchorTyped = $anchor->u1;
        assert($anchorTyped instanceof CData);
        $anchorValue = $anchor->value;
        assert($anchorValue instanceof CData);
        $anchorKind = $anchorTyped->v;
        assert($anchorKind instanceof CData);

        $slotType = $anchorKind->type;
        assert(is_int($slotType));
        if ($slotType === ReflectionValue::IS_PTR) {
            $registryPointer = $anchorValue->ptr;
            assert($registryPointer instanceof CData);

            return PersistentHashTable::fromCData(Core::cast('HashTable *', $registryPointer));
        }

        $registry = new PersistentHashTable();

        // Store through the typed union member (zend_array*): bare void* CData round
        // trips are unreliable for address identity
        $anchorValue->arr       = $registry->getRawValue();
        $anchorTyped->type_info = ReflectionValue::IS_PTR;

        return $registry;
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
    private function anchorSlot(): CData
    {
        $rawGlobals = ReflectionExtension::getGlobals();
        if ($rawGlobals === null) {
            throw new PersistentHeapException('The zengine module has no globals block');
        }

        return Core::cast('zval *', $rawGlobals);
    }
}
