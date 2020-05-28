Z-Engine library
-----------------

[![Build Status](https://img.shields.io/travis/com/lisachenko/z-engine/master)](https://travis-ci.org/lisachenko/z-engine)
[![GitHub release](https://img.shields.io/github/release/lisachenko/z-engine.svg)](https://github.com/lisachenko/z-engine/releases/latest)
[![Minimum PHP Version](http://img.shields.io/badge/php-%3E%3D%207.4-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/packagist/l/lisachenko/z-engine.svg)](https://packagist.org/packages/lisachenko/z-engine)

Have you ever dreamed about mocking a final class or redefining final method? Or maybe have an ability to work with existing classes in runtime?
`Z-Engine` is a PHP7.4 library that provides an API to PHP. Forget about all existing limitations and use this library to transform your existing code in runtime by declaring new methods, adding new interfaces to the classes and even installing your own system hooks, like opcode compilation, object initalization and much more.

**:warning: DO NOT USE IT IN PRODUCTION UNTIL 1.0.0!**

How it works?
------------

As you know, PHP version 7.4 contains a new feature, called [FFI](https://www.php.net/manual/en/book.ffi.php). It allows the loading of shared libraries (.dll or .so), calling of C functions and accessing of C data structures in pure PHP, without having to have deep knowledge of the Zend extension API, and without having to learn a third "intermediate" language.

`Z-Engine` uses FFI to access internal structures of... PHP itself. This idea was so crazy to try, but it works! `Z-Engine` loads definition of native PHP structures, like `zend_class_entry`, `zval`, etc and manipulates them in runtime. Of course, it is dangerous, since `FFI` allows to work with structures on a very low level. Thus, you should expect segmentation faults, memory leaks and other bad things.

Pre-requisites and initialization
--------------

As this library depends on `FFI`, it requires PHP>=7.4 and `FFI` extension to be enabled.
It should work in CLI mode without any troubles, whereas for web mode `preload` mode should be activated.
Also, current version is limited to x64 non-thread-safe versions of PHP.

To install this library, simply add it via `composer`:
```bash
composer require lisachenko/z-engine
```
To activate a `preload` mode, please add `Core::preload()` call into your script, specified by `opcache.preload`. This call will be done during the server preload and will be used by library to bypass unnecessary C headers processing during each request.

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

ObjectStore API
-------------

Every object in PHP has it's own unique identifier, which can be received via `spl_object_id($object)`. Sometimes
we are looking for the way to get an object by it's identifier. Unfortunately, PHP doesn't provide such an API, whereas
internally there is an instance of `zend_objects_store` structure which is stored in the global `executor_globals`
variable (aka EG).

This library provides an `ObjectStore` API via `Core::$executor->objectStore` which implements an `ArrayAccess` and
`Countable` interface. This means that you can get any existing object by accessing this store with object handle:

```php
use ZEngine\Core;

$instance = new stdClass();
$handle   = spl_object_id($instance);

$objectEntry = Core::$executor->objectStore[$handle];
var_dump($objectEntry);
```

Object Extensions API
---------------------

With the help of `z-engine` library it is possible to overload standard operators for your classes without diving deep
into the PHP engine implementation. For example, let's say you want to define native matrix operators and use it:

```php
<?php

use ZEngine\ClassExtension\ObjectCastInterface;
use ZEngine\ClassExtension\ObjectCompareValuesInterface;
use ZEngine\ClassExtension\ObjectCreateInterface;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\ClassExtension\ObjectDoOperationInterface;

class Matrix implements
    ObjectCreateInterface,
    ObjectCompareValuesInterface,
    ObjectDoOperationInterface,
    ObjectCastInterface
{
    use ObjectCreateTrait;

    // ...
}
$a = new Matrix([10, 20, 30]);
$b = new Matrix([1, 2, 3]);
$c = $a + $b; // Matrix([11, 22, 33])
$c *= 2;      // Matrix([22, 44, 66])
```

There are two ways of activating custom handlers.
First way is to implement several system interfaces like
`ObjectCastInterface`, `ObjectCompareValuesInterface`, `ObjectCreateInterface` and `ObjectDoOperationInterface`. After
that you should create an instance of `ReflectionClass` provided by this package and call `installExtensionHandlers`
method to install extensions:

```php
use ZEngine\Reflection\ReflectionClass as ReflectionClassEx;

// ... initialization logic

$refClass = new ReflectionClassEx(Matrix::class);
$refClass->installExtensionHandlers();
```

if you don't have an access to the code (eg. vendor), then you can still have an ability to define custom handlers.
You need to define callbacks as closures explicitly and assign them via `set***Handler()` methods in the
`ReflectionClass`.

```php
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\Reflection\ReflectionClass as ReflectionClassEx;

$refClass = new ReflectionClassEx(Matrix::class);
$handler  = Closure::fromCallable([ObjectCreateTrait::class, '__init']);
$refClass->setCreateObjectHandler($handler);
$refClass->setCompareValuesHandler(function ($left, $right) {
    if (is_object($left)) {
        $left = spl_object_id($left);
    }
    if (is_object($right)) {
        $right = spl_object_id($right);
    }

    // Just for example, object with bigger object_id is considered bigger that object with smaller object_id
    return $left <=> $right;
});
```

Library provides following interfaces:

First one is `ObjectCastInterface` which provides a hook for handling casting a class instance to scalars. Typical
examples are following: 1) explicit `$value = (int) $objectInstance` or implicit: `$value = 10 + $objectInstance;` in
the case when `do_operation` handler is not installed. Please note, that this handler doesn't handle casting to `array`
type as it is implemented in a different way.

```php
<?php
use ZEngine\ClassExtension\Hook\CastObjectHook;

/**
 * Interface ObjectCastInterface allows to cast given object to scalar values, like integer, floats, etc
 */
interface ObjectCastInterface
{
    /**
     * Performs casting of given object to another value
     *
     * @param CastObjectHook $hook Instance of current hook
     *
     * @return mixed Casted value
     */
    public static function __cast(CastObjectHook $hook);
}
```
To get the type of casting, you should check `$hook->getCastType()` method which will return the integer value of type.
Possible values are declared as public constants in the `ReflectionValue` class. For example `ReflectionValue::IS_LONG`.

Next `ObjectCompareValuesInterface` interface is used to control the comparison logic. For example, you can compare
two objects or even compare object with scalar values: `if ($object > 10 || $object < $anotherObject)`

```php
<?php
use ZEngine\ClassExtension\Hook\CompareValuesHook;

/**
 * Interface ObjectCompareValuesInterface allows to perform comparison of objects
 */
interface ObjectCompareValuesInterface
{
    /**
     * Performs comparison of given object with another value
     *
     * @param CompareValuesHook $hook Instance of current hook
     *
     * @return int Result of comparison: 1 is greater, -1 is less, 0 is equal
     */
    public static function __compare(CompareValuesHook $hook): int;
}
```
Handler should check arguments which can be received by calling `$hook->getFirst()` and `$hook->getSecond()` methods
(one of them should return an instance of your class) and return integer result -1..1. Where
1 is greater, -1 is less and 0 is equal.

The interface `ObjectDoOperationInterface` is the most powerful one because it gives you control over math operators
applied to your object (such as ADD, SUB, MUL, DIV, POW, etc).

```php
<?php
use ZEngine\ClassExtension\Hook\DoOperationHook;

/**
 * Interface ObjectDoOperationInterface allows to perform math operations (aka operator overloading) on object
 */
interface ObjectDoOperationInterface
{
    /**
     * Performs an operation on given object
     *
     * @param DoOperationHook $hook Instance of current hook
     *
     * @return mixed Result of operation value
     */
    public static function __doOperation(DoOperationHook $hook);
}
```
This handler receives an opcode (see `OpCode::*` constants) via `$hook->getOpcode()` and two arguments (one of them is
an instance of class) via `$hook->getFirst()` and `$hook->getSecond()` and returns a value for that operation.
In this handler you can return a new instance of your object to have a chain of immutable instances of objects.

Important reminder: you **MUST** install the `create_object` handler first in order to install hooks in runtime. Also
you can not install the `create_object` handler for the object if it is internal one.

There is one extra method called `setInterfaceGetsImplementedHandler` which is useful for installing special handler for
interfaces. The `interface_gets_implemented` callback uses the same memory slot as `create_object` handler for object,
and will be called each time when any class will implement this interface. This gives interesting options for
automatic class extensions registration, for example, if a class implements the `ObjectCreateInterface` then
automatically call `ReflectionClass->installExtensionHandlers()` for it in callback.

Abstract Syntax Tree API
--------------

As you know, PHP7 uses an abstract syntax tree for working with abstract model of source code to simplify future
development of language syntax. Unfortunately, this information is not provided back to the userland level. There are
several PHP extensions like [nikic/php-ast](https://github.com/nikic/php-ast) and
[sgolemon/astkit](https://github.com/sgolemon/astkit/) that provide low-level bindings to the underlying AST structures.
`Z-Engine` provides access to the AST via `Compiler::parseString(string $source, string $fileName = '')` method. This
method will return a top-level node of tree that implements `NodeInterface`. PHP has four types of AST nodes, they are:
declaration node (classes, methods, etc), list node (can contain any number of children nodes), simple node (contains
up to 4 children nodes, depending of type) and special value node class that can store any value in it (typically string
or numeric).

Here are an example of parsing simple PHP code:

```php
use ZEngine\Core;

$ast = Core::$compiler->parseString('echo "Hello, world!", PHP_EOL;', 'hi.php');
echo $ast->dump();
```
Output will be like that:
```
   1: AST_STMT_LIST
   1:   AST_STMT_LIST
   1:     AST_ECHO
   1:       AST_ZVAL string('Hello, world!')
   1:     AST_ECHO
   1:       AST_CONST
   1:         AST_ZVAL attrs(0001) string('PHP_EOL')
```

Node provides simple API to mutate children nodes via call to the `Node->replaceChild(int $index, ?Node $node)`. You can
create your own nodes in runtime or use a result from `Compiler::parseString(string $source, string $fileName = '')` as
replacement for your code.

Modifying the Abstract Syntax Tree
--------------
When PHP 7 compiles PHP code it converts it into an abstract syntax tree (AST) before finally generating Opcodes that
are persisted in Opcache. The `zend_ast_process` hook is called for every compiled script and allows you to modify the
AST after it is parsed and created.

To install the `zend_ast_process` hook, make a static call to the `Core::setASTProcessHandler(Closure $callback)`
method that accepts a callback which will be called during AST processing and will receive a `AstProcessHook $hook` as
an argument. You can access top-level node item via `$hook->getAST(): NodeInterface` method.

```php
use ZEngine\Core;
use ZEngine\System\Hook\AstProcessHook;

Core::setASTProcessHandler(function (AstProcessHook $hook) {
    $ast = $hook->getAST();
    echo "Parsed AST:", PHP_EOL, $ast->dump();
    // Let's modify Yes to No )
    echo $ast->getChild(0)->getChild(0)->getChild(0)->getValue()->setNativeValue('No');
});

eval('echo "Yes";');

// Parsed AST:
//    1: AST_STMT_LIST
//    1:   AST_STMT_LIST
//    1:     AST_ECHO
//    1:       AST_ZVAL string('Yes')
// No
```

You can see that result of evaluation is changed from "Yes" to "No" because we have adjusted given AST in our callback.
But be aware, that this is one of the most complicated hooks to use, because it requires perfect understanding of the
AST possibilities. Creating an invalid AST here can cause weird behavior or crashes.

Creating PHP extensions in runtime
--------------
The most interesting part of Z-Engine library is creating your own PHP extensions in PHP language itself.
You do not have to spend a lot of time learning the C language; instead, you can use the ready-made API to create
your own extension module from PHP itself!

Of course, not everything is possible to implement as an extension in PHP, for example, changing the parser syntax
or changing the logic of opcache - for this you will have to delve into the code of the engine itself.

Let's make an example a module with global variables, an analog of apcu, so that these variables are not cleared
after the request is completed. It is believed that PHP has the concept of share nothing and therefore can’t survive
the boundary of the request, since at the time of completion of the request PHP will automatically free all allocated
memory for objects. However, PHP itself can work with global variables, and they are stored inside loaded modules by
the pointer `zend_module_entry.globals_ptr`.

Therefore, if we can register the module in PHP and allocate global memory for it, PHP will not clear it, and our
module will be able to survive the boundary of the request.

Technically, every module is represented by following structure:

```
struct _zend_module_entry {
    unsigned short size;
    unsigned int zend_api;
    unsigned char zend_debug;
    unsigned char zts;
    const struct _zend_ini_entry *ini_entry;
    const struct _zend_module_dep *deps;
    const char *name;
    const struct _zend_function_entry *functions;
    int (*module_startup_func)(int type, int module_number);
    int (*module_shutdown_func)(int type, int module_number);
    int (*request_startup_func)(int type, int module_number);
    int (*request_shutdown_func)(int type, int module_number);
    void (*info_func)(zend_module_entry *zend_module);
    const char *version;
    size_t globals_size;
#ifdef ZTS
    ts_rsrc_id* globals_id_ptr;
#endif
#ifndef ZTS
    void* globals_ptr;
#endif
    void (*globals_ctor)(void *global);
    void (*globals_dtor)(void *global);
    int (*post_deactivate_func)(void);
    int module_started;
    unsigned char type;
    void *handle;
    int module_number;
    const char *build_id;
};
```
You can see that we can define several callbacks and there are several fields with meta-information about zts, debug,
API version, etc that are used by PHP to check if this module can be loaded for current environment.

From PHP side, you should extend your module class from the `AbstractModule` class that contains general logic of
module registration and startup and implement all required method from the `ModuleInterface`.

Let's have a look at our simple module:
```php
use ZEngine\EngineExtension\AbstractModule;

class SimpleCountersModule extends AbstractModule
{
    /**
     * Returns the target thread-safe mode for this module
     *
     * Use ZEND_THREAD_SAFE as default if your module does not depend on thread-safe mode.
     */
    public static function targetThreadSafe(): bool
    {
        return ZEND_THREAD_SAFE;
    }

    /**
     * Returns the target debug mode for this module
     *
     * Use ZEND_DEBUG_BUILD as default if your module does not depend on debug mode.
     */
    public static function targetDebug(): bool
    {
        return ZEND_DEBUG_BUILD;
    }

    /**
     * Returns the target API version for this module
     *
     * @see zend_modules.h:ZEND_MODULE_API_NO
     */
    public static function targetApiVersion(): int
    {
        return 20190902;
    }

    /**
     * Returns true if this module should be persistent or false if temporary
     */
    public static function targetPersistent(): bool
    {
        return true;
    }

    /**
     * Returns globals type (if present) or null if module doesn't use global memory
     */
    public static function globalType(): ?string
    {
        return 'unsigned int[10]';
    }
}
```
Our `SimpleCountersModule` declares that it will use array of 10 unsigned ints. It also provides some information about
required environment (debug/zts/API version). Important option is to mark our module persistent by returning true from
`targetPersistent()` method. And now we are ready to register it and use it:

```php
$module = new SimpleCountersModule();
if (!$module->isModuleRegistered()) {
    $module->register();
    $module->startup();
}

$data = $module->getGlobals();
var_dump($data);
```
Note, that on subsequent requests module will be registered, this is why you should not call register twice.
What is really cool is that any changes in module globals are **true globals**! They will be **preserved** between
requests. Try to update each item to see that values in our array are increasing between requests:

```php
$index        = mt_rand(0, 9); // If you have several workers, you should use worker pid to avoid race conditions
$data[$index] = $data[$index] + 1; // We are increasing global counter by one

/* Example of var_dump after several requests...
object(FFI\CData:uint32_t[10])#35 (10) {
  [0]=>
  int(1)
  [1]=>
  int(1)
  [2]=>
  int(1)
  [3]=>
  int(3)
  [4]=>
  int(1)
  [5]=>
  int(1)
  [6]=>
  int(1)
  [7]=>
  int(2)
  [8]=>
  int(2)
  [9]=>
  int(2)
}*/
```

Of course, module can declare any complex structure for globals and use it as required. If module requires some
initialization, then you can implement the `ControlModuleGlobalsInterface` in your module and this callback will be
called during module startup procedure. This may be useful for registration of additional hooks, class extensions, etc
or for global variable initialization (filling it with predefined values, restoring state from DB/filesystem/etc)

Code of Conduct
--------------

This project adheres to the Contributor Covenant [code of conduct](CODE_OF_CONDUCT.md).
By participating, you are expected to uphold this code.
Please report any unacceptable behavior.

License
-------

In order help ensure fairness and sharing, this library is dual-licensed. Be
aware that _all_ usage, unless otherwise specified, is under the [**RPL-1.5** license](LICENSE)!

- Reciprocal Public License 1.5 (RPL-1.5): https://opensource.org/licenses/RPL-1.5

You should read the _entire_ license; especially the `PREAMBLE` at the
beginning. In short, the word `reciprocal` means "giving something back in
return for what you are getting". It is _**not** a freeware license_. This
license _requires_ that you open-source _all_ of your own source code for _any_
project which uses this library! Creating and maintaining this library is
endless hard work for me. That's why there is _one_ simple requirement for you:
Give _something_ back to the world. Whether that's code _or_ financial support
for this project is entirely up to you, but _nothing else_ grants you _any_
right to use this library.

Furthermore, the library is _also_ available _to certain entities_ under a
modified version of the RPL-1.5, which has been modified to allow you to use the
library _without_ open-sourcing your own project. The modified license
(see [LICENSE_PREMIUM](LICENSE_PREMIUM))
is granted to certain entities, at _our_ discretion, and for a _limited_ period
of time (unless otherwise agreed), pursuant to our terms. Currently, we are
granting this license to all
premium subscribers for
the duration of their subscriptions. You can become a premium subscriber by
either contributing substantial amounts of high-quality code, or by subscribing
for a fee. This licensing ensures fairness and stimulates the continued growth
of this library through both code contributions and the financial support it
needs.

You are not required to accept this License since you have not signed it,
however _nothing else_ grants you permission to _use_, copy, distribute, modify,
or create derivatives of either the Software (this library) or any Extensions
created by a Contributor. These actions are prohibited by law if you do not
accept this License. Therefore, by performing any of these actions You indicate
Your acceptance of this License and Your agreement to be bound by all its terms
and conditions. IF YOU DO NOT AGREE WITH ALL THE TERMS AND CONDITIONS OF THIS
LICENSE DO NOT USE, MODIFY, CREATE DERIVATIVES, OR DISTRIBUTE THE SOFTWARE. IF
IT IS IMPOSSIBLE FOR YOU TO COMPLY WITH ALL THE TERMS AND CONDITIONS OF THIS
LICENSE THEN YOU CAN NOT USE, MODIFY, CREATE DERIVATIVES, OR DISTRIBUTE THE
SOFTWARE.
