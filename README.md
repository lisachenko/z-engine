Z-Engine library
-----------------

Have you ever dreamed about mocking a final class or redefining final method? Or maybe have an ability to work with existing classes in runtime?
`Z-Engline` is a PHP7.4 library that provides an API to PHP. Forget about all existing limitations and use this library to transform your existing code in runtime by declaring new methods, adding new interfaces to the classes and even installing your own system hooks, like opcode compilation, object initalization and much more.

[![Build Status](https://secure.travis-ci.org/lisachenko/z-engine.png?branch=master)](https://travis-ci.org/lisachenko/z-engine)
[![GitHub release](https://img.shields.io/github/release/lisachenko/z-engine.svg)](https://github.com/lisachenko/z-engine/releases/latest)
[![Total Downloads](https://img.shields.io/packagist/dt/lisachenko/z-engine.svg)](https://packagist.org/packages/lisachenko/z-engine)
[![Daily Downloads](https://img.shields.io/packagist/dd/lisachenko/z-engine.svg)](https://packagist.org/packages/lisachenko/z-engine)
[![Minimum PHP Version](http://img.shields.io/badge/php-%3E%3D%207.4-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/packagist/l/lisachenko/z-engine.svg)](https://packagist.org/packages/lisachenko/z-engine)

How it works?
------------

As you know, PHP version 7.4 contains a new feature, called [FFI](https://www.php.net/manual/en/book.ffi.php). It allows the loading of shared libraries (.dll or .so), calling of C functions and accessing of C data structures in pure PHP, without having to have deep knowledge of the Zend extension API, and without having to learn a third "intermediate" language.

`Z-Engine` uses FFI to access internal structures of... PHP itself. This idea was so crazy to try, but it works! `Z-Engine` loads definition of native PHP structures, like `zend_class_entry`, `zval`, etc and manipulates them in runtime. Of course, it is dangerous, since `FFI` allows to work with structures on a very low level. Thus, you should expect segmentation faults, memory leaks and other bad things.

**DO NOT USE IT IN PRODUCTION UNTIL 1.0.0!**

Pre-requisites and initialization
--------------

As this library depends on `FFI`, it requires PHP>=7.4 and `FFI` extension to be enabled.
It should work in CLI mode without any troubles, whereas for web mode `preload` mode should be implemented (not done yet), so please configure `ffi.enable` to be `true`.
Also, current version is limited to x64 non-thread-safe versions of PHP.

To install this library, simply add it via `composer`:
```shell script
composer require lisachenko/z-engine
```

Next step is to init library itself with short call to the `Core::init()`:
```php
use ZEngine\Core;

include __DIR__.'/vendor/autoload.php';

Core::init();
```

Now you can test it with following example:
```php
<?php
declare(strict_types=1);

use ZEngine\Reflection\ReflectionClass;

include __DIR__.'/vendor/autoload.php';

final class FinalClass {}

$refClass = new ReflectionClass(FinalClass::class);
$refClass->setFinal(false);

eval('class TestClass extends FinalClass {}'); // Should be created
```

To have an idea, what you can do with this library, please see library tests as an example.

ReflectionClass
------------

Library provides and extension for classic reflection API to manipulate internal structure of class via `ReflectionClass`:
  - `setFinal(bool $isFinal = true): void` Makes specified class final/non-final
  - `setAbstract(bool $isAbstract = true): void` Makes specified class abstract/non-abstract. Even if it contains non-implemented methods from interface or abstract class.
  - `setStartLine(int $newStartLine): void` Updates meta-information about the class start line
  - `setEndLine(int $newEndLine): void` Updates meta-information about the class end line
  - `setFileName(string $newFileName): void` Sets a new filename for this class
  - `setParent(string $newParent)` \[WIP\] Configures a new parent class for this one
  - `removeParentClass(): void` \[WIP\] Removes the parent class
  - `removeTraits(string ...$traitNames): void` \[WIP\] Removes existing traits from the class
  - `addTraits(string ...$traitNames): void` \[WIP\] Adds new traits to the class
  - `removeMethods(string ...$methodNames): void` Removes list of methods from the class
  - `addMethod(string $methodName, \Closure $method): ReflectionMethod` Adds a new method to the class
  - `removeInterfaces(string ...$interfaceNames): void` Removes a list of interface names from the class
  - `addInterfaces(string ...$interfaceNames): void` Adds a list of interfaces to the given class.

Beside that, all methods that return `ReflectionMethod` or `ReflectionClass` were decorated to return an extended object with low-level access to native structures.

ReflectionMethod
-------------

 `ReflectionMethods` contains methods to work with a definition of existing method:

   - `setFinal(bool $isFinal = true): void` Makes specified method final/non-final
   - `setAbstract(bool $isAbstract = true): void` Makes specified method abstract/non-abstract.
   - `setPublic(): void` Makes specified method public
   - `setProtected(): void` Makes specified method protected
   - `setPrivate(): void` Makes specified method private
   - `setStatic(bool $isStatic = true): void` Declares method as static/non-static
   - `setDeclaringClass(string $className): void` Changes the declaring class name for this method
   - `setDeprecated(bool $isDeprecated = true): void` Declares this method as deprecated/non-deprecated
   - `redefine(\Closure $newCode): void` Redefines this method with a closure definition
   - `getOpCodes(): iterable`: \[WIP\] Returns the list of opcodes for this method
