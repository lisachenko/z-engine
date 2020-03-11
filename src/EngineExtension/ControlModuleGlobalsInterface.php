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

namespace ZEngine\EngineExtension;

use ZEngine\EngineExtension\Hook\ExtensionConstructorHook;

/**
 * Interface ControlModuleGlobalsInterface allows to intercept module initialization/shutdown
 */
interface ControlModuleGlobalsInterface
{
    /**
     * Callback which is called when initializing module globals
     *
     * @param ExtensionConstructorHook $hook Instance of current hook
     */
    public static function __globalConstruct(ExtensionConstructorHook $hook): void;
}
