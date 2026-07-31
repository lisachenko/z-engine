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

/**
 * Opt-in phpinfo() output for userland modules
 *
 * When a module class implementing this interface is registered via AbstractModule::register(),
 * an FFI-closure trampoline is written into the module entry's `info_func` slot. Whenever the
 * engine renders module information (phpinfo(), phpinfo(INFO_MODULES), CLI `php -i`), the
 * rows returned by getDisplayInfo() are rendered into the module's own section by
 * AbstractModule::printInfoTable() - as `label => value` lines in text mode and as a table
 * in HTML mode.
 */
interface ModuleInfoInterface
{
    /**
     * Returns the rows to render into this module's phpinfo() section
     *
     * @return array<string, scalar> Map from row label to row value
     */
    public function getDisplayInfo(): array;
}
