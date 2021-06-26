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

use ZEngine\ClassExtension\Hook\ReadPropertyHook;

/**
 * Interface ObjectReadPropertyInterface allows to intercept property reads and modify values
 */
interface ObjectReadPropertyInterface
{
    /**
     * Performs reading of object's field
     *
     * @return mixed Value to return
     */
    public static function __fieldRead(ReadPropertyHook $hook);
}
