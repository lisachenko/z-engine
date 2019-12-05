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

/**
 * Interface ObjectDoOperationInterface allows to perform math operations (aka operator overloading) on object
 */
interface ObjectDoOperationInterface
{
    /**
     * Performs casting of given object to another value
     *
     * @param int $opCode Operation code
     * @param mixed $left left side of operation
     * @param mixed $right Right side of operation
     *
     * @return mixed Result of operation value
     */
    public static function __doOperation(int $opCode, $left, $right);
}
