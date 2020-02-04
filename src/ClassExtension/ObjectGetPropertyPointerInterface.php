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

namespace ZEngine\ClassExtension;

use ZEngine\ClassExtension\Hook\GetPropertyPointerHook;

/**
 * Interface ObjectGetPropertyPointerInterface allows to intercept creation of pointers to properties (indirect changes)
 */
interface ObjectGetPropertyPointerInterface
{
    /**
     * Returns a pointer to an object's field
     *
     * @param GetPropertyPointerHook $hook Instance of current hook
     *
     * @return mixed Value to return
     */
    public static function __fieldPointer(GetPropertyPointerHook $hook);
}
