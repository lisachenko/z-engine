<?php
$version = PHP_MAJOR_VERSION."-" . PHP_MINOR_VERSION . "-" . (PHP_INT_SIZE === 8 ? "x64" : "x32") . "-" . (ZEND_THREAD_SAFE ? "zts" : "nts") . "-" . strtolower(PHP_OS_FAMILY);
return file_get_contents(__DIR__."/engine-$version.h");
