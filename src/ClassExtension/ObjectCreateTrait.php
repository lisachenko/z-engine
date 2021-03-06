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

use FFI\CData;
use ZEngine\ClassExtension\Hook\CreateObjectHook;

/**
 * Trait ObjectCreateTrait contains default hook implementation for object initialization
 */
trait ObjectCreateTrait
{
    /**
     * Performs low-level initialization of object during new instances creation
     *
     * @return CData Pointer to the zend_object instance
     */
    public static function __init(CreateObjectHook $hook): CData
    {
        return $hook->proceed();
    }
}
