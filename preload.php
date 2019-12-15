<?php
/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */
declare(strict_types=1);

include __DIR__.'/vendor/autoload.php';

use ZEngine\Core;

/**
 * This file should be loaded during the preload stage, which is defined by opcache.preload file.
 * Either include it manually, or just add following line into your init section.
 */
Core::preload();

/*全局变量*/
//Core::$engine->zend_throw_exception_hook = function($ex){


   	//Core::call("php_printf","Caught\n");
   	//echo "Caught\n";

    //zval_ptr_dtor(ex); // destroy the thrown object
    //Core::call("zval_ptr_dtor",$ex);
    
    //EG(exception) = NULL; // nullify it, as if it never happened

    //Core::$engine->executor_globals->exception = NULL;
//};

