<?php
declare(strict_types=1);

use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;

include __DIR__.'/../vendor/autoload.php';

Core::init();


final class FinalClass {
	public function testFn123(){
		var_dump($this);
	}
}

$refClass = new ReflectionClass(FinalClass::class);
$refClass->setFinal(false);


/**/
eval('class TestClass extends FinalClass {}'); // Should be created

$obj = new TestClass();
var_dump($obj);


$pobj = new FinalClass();
var_dump("pobj",is_callable([$pobj,"newFunction"]));

/*添加一个方法*/
$refClass->addMethod("newFunction",function(){
	var_dump($this);
});

$pobj2 = new FinalClass();
var_dump("obj",is_callable([$obj,"newFunction"]));

/*添加之后马上生效，但是继承的子类没有生效*/
var_dump("pobj",is_callable([$pobj,"newFunction"]));
var_dump("pobj2",is_callable([$pobj2,"newFunction"]));

eval('class TestClass2 extends FinalClass {}'); // Should be created

$obj2 = new TestClass2();
var_dump($obj2);

var_dump("obj2",is_callable([$obj2,"newFunction"]));

if(false){
/*下面这个会段错误*/
$testFn123 = $refClass->getMethod("testFn123");
$back = $testFn123->getClosure($pobj);
var_dump($back);
$testFn123->redefine(function() use ($back){
	echo "changed!",PHP_EOL;

	$back();
});


$pobj->testFn123();
}