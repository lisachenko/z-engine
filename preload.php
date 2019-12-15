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

//global $orig_zend_write;
$orig_zend_write = clone Core::$engine->zend_write;


/*全局变量*/
Core::$engine->zend_write = function($str, $len){
    //global $orig_zend_write;
    //$orig_zend_write("{\n\t", 3);
    $ret = $orig_zend_write($str, $len);
    //$orig_zend_write("}\n", 2);
    return $ret;
};


// Core::$engine->zend_throw_exception_hook = function($ex){


//    	Core::call("zend_write","Caught\n");
//    	//echo "Caught\n";

//     //zval_ptr_dtor(ex); // destroy the thrown object
//     //Core::call("zval_ptr_dtor",$ex);
    
//     //EG(exception) = NULL; // nullify it, as if it never happened

//     //Core::$executor->exception = NULL;
//     //
//     //return;
// };

