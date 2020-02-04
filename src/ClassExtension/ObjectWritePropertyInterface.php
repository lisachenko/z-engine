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

use ZEngine\ClassExtension\Hook\WritePropertyHook;

/**
 * Interface ObjectWritePropertyInterface allows to intercept property writes and modify values
 */
interface ObjectWritePropertyInterface
{
    /**
     * Performs writing of value to object's field
     *
     * @param WritePropertyHook $hook Instance of current hook
     *
     * @return mixed New value to write, return given $value if you don't want to adjust it
     */
    public static function __fieldWrite(WritePropertyHook $hook);
}
