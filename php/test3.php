<?php 

declare(strict_types=1);

use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;

include __DIR__.'/../vendor/autoload.php';

Core::init();


/*修改标准库的方法*/
$refClass = new ReflectionClass(StdClass::class);
$refClass->addMagicMethod('__construct',function(){
	var_dump($this);

	echo "__construct",PHP_EOL;
});

$refClass->addMethod("newFunction",function(){
	var_dump($this);
});

$a = new StdClass();

var_dump($a,is_callable([$a,"newFunction"]));

 $b = 1;

 settype($b,gettype($a));

var_dump($b,is_callable([$b,"newFunction"]));


// class Obj{
// 	public object $cc;
// }

// $obj1 = new Obj;
// $obj1->cc = 100;

// var_dump($obj1->cc,is_callable([$obj1->cc,"newFunction"]));