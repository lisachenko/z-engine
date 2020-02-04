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
     * Performs installation of current hook
     */
    public function install(): void;

    /**
     * Checks if original handler is present to call it later with proceed
     */
    public function hasOriginalHandler(): bool;
}