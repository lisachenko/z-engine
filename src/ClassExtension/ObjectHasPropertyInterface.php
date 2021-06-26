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

use ZEngine\ClassExtension\Hook\HasPropertyHook;

/**
 * Interface ObjectHasPropertyInterface allows to intercept property isset/has checks
 */
interface ObjectHasPropertyInterface
{
    /**
     * Performs checking of object's field
     *
     * @return int Value to return
     */
    public static function __fieldIsset(HasPropertyHook $hook);
}
