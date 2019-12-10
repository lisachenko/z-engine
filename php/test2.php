<?php 

class A{
	public $a = 100;
}


$objA = new A();

$objA->bbbbbb = 200;
$objA->fn1 = function(){};

$objA2 = clone $objA;


var_dump($objA2,is_callable([$objA2,'fn1']));