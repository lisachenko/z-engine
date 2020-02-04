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

use ZEngine\ClassExtension\Hook\DoOperationHook;

/**
 * Interface ObjectDoOperationInterface allows to perform math operations (aka operator overloading) on object
 */
interface ObjectDoOperationInterface
{
    /**
     * Performs an operation on given object
     *
     * @param DoOperationHook $hook Instance of current hook
     *
     * @return mixed Result of operation value
     */
    public static function __doOperation(DoOperationHook $hook);
}
