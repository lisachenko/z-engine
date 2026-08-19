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

namespace ZEngine\System\Hook;

use FFI\CData;
use ZEngine\Core;
use ZEngine\Hook\AbstractHook;

/**
 * Interceptor for the `zend_inheritance_cache_add` engine global callback (issue #241)
 *
 * Opcache publishes this pointer so `zend_do_link_class()`/`zend_try_early_bind()` can
 * persist a freshly linked class into the shared-memory inheritance cache. The engine
 * links such a class on a temporary mutable copy (`zend_lazy_class_load()`); when the
 * publication succeeds, the caller swaps the class-table bucket to the returned
 * shared-memory entry and the temporary is discarded - together with every z-engine
 * handler keyed to the temporary's address (issue #238).
 *
 * This hook makes handler installation during lazy linking stick: for a class entry
 * recorded in the Core decline set it returns NULL, which the engine treats as an
 * ordinary "not cached" outcome (opcache itself returns NULL when SHM is full or a
 * restart is pending) - the temporary stays in the class table as a process-local,
 * request-lifetime class, so the address-keyed handlers remain valid and no
 * process-local trampoline address is ever published into shared memory. Every other
 * class is delegated to the saved opcache callback unchanged.
 *
 * The callback runs DURING class linking - and, through compile-time early binding,
 * possibly while CG(in_compilation) is set, where the engine promotes EVERY thrown
 * exception to an immediate fatal error before any catch runs (see AstProcessHook).
 * So handle() is deliberately minimal AND throw-free on its hot path: no file
 * inclusion, no engine mutation, and in particular no Core::addressOf()/cast(),
 * whose array-decay probe throws-and-catches an FFI\Exception per call. Any
 * unexpected internal failure degrades to declining the publication, which is
 * always safe (the class simply stays process-local and is re-linked per request).
 */
final class InheritanceCacheAddHook extends AbstractHook
{
    protected const string HOOK_FIELD = 'zend_inheritance_cache_add';

    /**
     * zend_class_entry *(*zend_inheritance_cache_add)(
     *     zend_class_entry *ce, zend_class_entry *proto, zend_class_entry *parent,
     *     zend_class_entry **traits_and_interfaces, HashTable *dependencies);
     *
     * `ce` is the temporary linked copy (ZEND_ACC_LINKED set, ZEND_ACC_IMMUTABLE
     * clear - see the asserts in opcache's zend_accel_inheritance_cache_add) and
     * `proto` is the shared-memory original the temporary was loaded from, so the
     * decline set is keyed by the address of `ce`: that is exactly the entry an
     * interface_gets_implemented hook observed and installed handlers on.
     *
     * The user handler is the decline predicate `function (int $ceAddress): bool`
     * (Core::takeInheritanceCacheDecline()); it must not throw and must not touch
     * engine state.
     *
     * @inheritDoc
     * @return CData|null zend_class_entry* of the published SHM entry, or null when
     *                    the class was not cached (declined or refused by opcache)
     */
    #[\Override]
    public function handle(...$rawArguments): ?CData
    {
        [$classEntry, $prototype, $parent, $traitsAndInterfaces, $dependencies] = $rawArguments;

        try {
            assert($classEntry instanceof CData);
            // Throw-free pointer identity: a direct reinterpreting cast, unlike
            // Core::addressOf(), whose array-decay probe throws internally - fatal
            // when this callback fires during compile-time early binding
            if (($this->userHandler)(Core::pointerAddressOf($classEntry)) === true) {
                // Declined: the engine keeps the process-local temporary in the class table
                return null;
            }
            if (!$this->hasOriginalHandler()) {
                return null;
            }
            $publishedEntry = ($this->getOriginalCallable())(
                $classEntry,
                $prototype,
                $parent,
                $traitsAndInterfaces,
                $dependencies,
            );
            assert($publishedEntry === null || $publishedEntry instanceof CData);

            return $publishedEntry;
        } catch (\Throwable) {
            // Nothing may escape an engine callback that runs mid-linking (issue #50);
            // declining the publication is the always-safe degradation
            return null;
        }
    }
}
