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

namespace ZEngine\EngineExtension\Hook;

use FFI\CData;
use ZEngine\Hook\AbstractHook;

/**
 * Receiving hook for extension global memory constructor
 */
class ExtensionConstructorHook extends AbstractHook
{
    protected const HOOK_FIELD = 'globals_ctor';

    private CData $globalMemoryPointer;

    /**
     * Returns a raw memory pointer
     */
    public function getMemoryPointer(): CData
    {
        return $this->globalMemoryPointer;
    }

    /**
     * void (*globals_ctor)(void *global);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): void
    {
        [$this->globalMemoryPointer] = $rawArguments;

        ($this->userHandler)($this);
    }

    /**
     * Proceeds with default implementation (if present)
     */
    public function proceed(): void
    {
        if ($this->originalHandler !== null) {
            ($this->originalHandler)($this->globalMemoryPointer);
        }
    }
}
