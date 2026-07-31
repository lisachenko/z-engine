<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\System\NativeFunction;

use Closure;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\Reflection\ReflectionMethod;

/**
 * Compiles PHP source into genuine engine functions and methods that run with no FFI trampoline
 *
 * The source is compiled to a real zend_op_array through the engine's normal compile path and
 * then published straight into the engine function/method table via ReflectionFunction::addFunction()
 * / ReflectionClass::addMethod(). The result dispatches through the ordinary Zend VM - there is no
 * per-call FFI boundary and no closure wrapper (see docs/memory-model.md, "Path B").
 *
 * This is a convenience layer over the closure-graft pipeline: the compiled body is materialized as
 * a closure and then handed to the same registration path a hand-written closure would take, so it
 * inherits the exact same memory contract (the closure body is immortalized, global functions are
 * unpublished at Core::shutdown()).
 *
 * Honest boundary: the generated code runs at normal PHP/VM speed, not literally C speed - pure PHP
 * cannot emit machine code. The win is eliminating the trampoline tax, not turning bytecode into a
 * native zif_handler.
 */
final class NativeCompiler
{
    /**
     * Compiles a signature + body into a closure through the engine compile path
     *
     * @param string $signature Parameter list as written in a function declaration, eg 'int $x, int $y'
     * @param string $body      Function body statements, eg 'return $x + $y;'
     */
    public static function compile(string $signature, string $body): Closure
    {
        // Materialize the source through the normal compiler so it becomes a real op_array; the
        // resulting closure is then grafted into the engine table by the registration paths below
        $closure = eval(sprintf('return static function (%s) {%s};', $signature, $body));
        if (!$closure instanceof Closure) {
            throw new \RuntimeException('Compilation did not produce a closure');
        }

        return $closure;
    }

    /**
     * Compiles PHP source into a genuine global function registered in the engine
     *
     * After this call function_exists($functionName) is true and calling it dispatches through the
     * normal VM with no FFI trampoline.
     *
     * @param string $functionName Name to register the function under
     * @param string $signature    Parameter list, eg 'int $x'
     * @param string $body         Function body statements, eg 'return $x * $x;'
     */
    public static function compileFunction(string $functionName, string $signature, string $body): ReflectionFunction
    {
        return ReflectionFunction::addFunction($functionName, self::compile($signature, $body));
    }

    /**
     * Compiles PHP source into a genuine method on an existing class
     *
     * The class must already exist (declared in userland, or otherwise present in the engine class
     * table) - forging a class entry from scratch is out of scope.
     *
     * @param string $className  Existing class to attach the method to
     * @param string $methodName Method name to register
     * @param string $signature  Parameter list, eg 'int $x'
     * @param string $body       Method body statements
     */
    public static function compileMethod(
        string $className,
        string $methodName,
        string $signature,
        string $body,
    ): ReflectionMethod {
        return (new ReflectionClass($className))->addMethod($methodName, self::compile($signature, $body));
    }
}
