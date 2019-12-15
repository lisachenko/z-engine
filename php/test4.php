<?php

declare(strict_types=1);

use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;

include __DIR__.'/../vendor/autoload.php';

Core::init();


$zend_throw_exception_hook = Core::$engine->zend_throw_exception_hook;

var_dump(Core::$engine->zend_throw_exception_hook);
// Core::$engine->zend_throw_exception_hook = function($ex){


//    	//Core::call("zend_write","Caught\n");
//    	//echo "Caught\n";

//     //zval_ptr_dtor(ex); // destroy the thrown object
//     //Core::call("zval_ptr_dtor",$ex);
    
//     //EG(exception) = NULL; // nullify it, as if it never happened

//     Core::$executor->exception = NULL;
//     //
//     //return;
// };

//throw new Exception();


/*
/usr/local/php74/bin/php -dopcache.preload=preload.php php/test4.php

/usr/local/php74/bin/php -dopcache.preload="`pwd`/preload.php" php/test1.php 


/usr/local/php74/bin/php php/test4.php

Fatal error: Uncaught Exception in /home/z/php/z-engine/php/test4.php:28
Stack trace:
#0 {main}

Next FFI\Exception: Cannot call callback in /home/z/php/z-engine/php/test4.php:28
Stack trace:
#0 {main}
  thrown in /home/z/php/z-engine/php/test4.php on line 28
 */