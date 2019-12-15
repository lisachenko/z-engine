<?php

declare(strict_types=1);

use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;

include __DIR__.'/../vendor/autoload.php';

Core::init();


throw new Exception();


/*
/usr/local/php74/bin/php -dopcache.preload=preload.php php/test4.php

/usr/local/php74/bin/php -dopcache.preload="`pwd`/preload.php" php/test1.php 

 */