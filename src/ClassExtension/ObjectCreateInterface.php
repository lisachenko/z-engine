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

use Closure;
use FFI\CData;

/**
 * Interface ObjectCreateInterface allows to hook into the object initialization process (eg new FooBar())
 */
interface ObjectCreateInterface
{
    /**
     * Performs low-level initialization of object during new instances creation
     *
     * @param CData   $classType Class type to initialize (zend_class_entry)
     * @param Closure $initializer Original initializer that accepts a zend_class_entry and creates a new zend_object
     *
     * @return CData Pointer to the zend_object instance
     */
    public static function __init(CData $classType, Closure $initializer): CData;
}
