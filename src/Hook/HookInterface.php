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

namespace ZEngine\Hook;

interface HookInterface
{
    /**
     * This method accepts raw C arguments for current hook and performs handling of this callback
     *
     * @param mixed ...$rawArguments
     */
    public function handle(...$rawArguments);

    /**
     * Performs installation of current hook (idempotent)
     */
    public function install(): void;

    /**
     * Restores the original engine pointer (idempotent)
     */
    public function uninstall(): void;

    /**
     * Checks if this hook is currently installed into the engine structure
     */
    public function isInstalled(): bool;

    /**
     * Checks if original handler is present to call it later with proceed
     */
    public function hasOriginalHandler(): bool;

    /**
     * Returns the identity of the engine slot this hook targets
     *
     * For struct-field hooks this is "<container address>::<field>", for slots that live
     * outside any struct (e.g. user opcode handlers) it is a synthetic key. Hooks with the
     * same key form one chain that unwinds in reverse installation order.
     *
     * @internal used by the Core hook registry to build per-slot chains
     */
    public function getHookFieldKey(): string;

    /**
     * Writes this hook's trampoline into the engine slot again without touching the chain
     *
     * @internal escape hatch used by Core::reinstallHooks() for SAPIs that cycle FFI state
     */
    public function refreshTrampoline(): void;
}
