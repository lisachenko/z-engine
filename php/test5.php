<?php 

declare(strict_types=1);

use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;

include __DIR__.'/../vendor/autoload.php';

Core::init();

// $zend = FFI::cdef("
//     typedef int (*zend_write_func_t)(const char *str, size_t str_length);
//     extern zend_write_func_t zend_write;
// ");
 
echo "Hello World 1!\n";
 
 $orig_zend_write = clone Core::$engine->zend_write;

 Core::$engine->zend_write = function($str, $len){
    global $orig_zend_write;
    $orig_zend_write("{\n\t", 3);
    $ret = $orig_zend_write($str, $len);
    $orig_zend_write("}\n", 2);
    return $ret;
};

// $orig_zend_write = clone $zend->zend_write;
// $zend->zend_write = function($str, $len) {
    // global $orig_zend_write;
//     $orig_zend_write("{\n\t", 3);
//     $ret = $orig_zend_write($str, $len);
//     $orig_zend_write("}\n", 2);
//     return $ret;
// };


echo "Hello World 2!\n";
Core::$engine->zend_write = $orig_zend_write;
echo "Hello World 3!\n";