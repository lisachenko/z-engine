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

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Target class for compiled-method tests
 */
class CompileTarget {}

class NativeCompilerTest extends TestCase
{
    #[Group('internal')]
    public function testCompileFunction(): void
    {
        // The generated function keeps its compiled body alive until request end
        // (immortal-by-design, see docs/memory-model.md)
        ini_set('report_memleaks', '0');

        $functionName = 'zengine_compiled_square';
        NativeCompiler::compileFunction($functionName, 'int $x', 'return $x * $x;');

        $this->assertTrue(function_exists($functionName));
        // Dispatches through the normal VM, no FFI trampoline
        // @phpstan-ignore callable.nonCallable (function is generated at runtime by compileFunction)
        $this->assertSame(81, $functionName(9));
    }

    #[Group('internal')]
    public function testCompileMethod(): void
    {
        ini_set('report_memleaks', '0');

        NativeCompiler::compileMethod(CompileTarget::class, 'add', 'int $a, int $b', 'return $a + $b;');

        $this->assertTrue(method_exists(CompileTarget::class, 'add'));
        $instance = new CompileTarget();
        // @phpstan-ignore method.notFound (method is generated at runtime by compileMethod)
        $this->assertSame(42, $instance->add(20, 22));
    }

    public function testCompileProducesAClosure(): void
    {
        $closure = NativeCompiler::compile('int $x', 'return $x + 1;');
        $this->assertInstanceOf(\Closure::class, $closure);
        $this->assertSame(2, $closure(1));
    }
}
