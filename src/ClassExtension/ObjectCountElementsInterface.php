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

namespace ZEngine\ClassExtension;

use ZEngine\ClassExtension\Hook\CountElementsHook;

/**
 * Interface ObjectCountElementsInterface allows to intercept count($object) calls
 */
interface ObjectCountElementsInterface
{
    /**
     * Performs counting of object's elements
     *
     * @return int Number of elements to report
     */
    public static function __count(CountElementsHook $hook): int;
}
