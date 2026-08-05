<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\Reflection;

use PHPUnit\Framework\TestCase;

/**
 * Safety semantics of the body-destroying redefine() (issue #64): a body that is
 * still executing on the current call stack must not be freed under the VM.
 */
class RedefineSafetyTest extends TestCase
{
    public function testSelfRedefinitionKeepsTheExecutingBodyAlive(): void
    {
        $functionName = 'zengine_self_redefining_' . substr(md5(__METHOD__), 0, 8);
        eval(<<<PHP
        function {$functionName}(): string
        {
            \$ref = new \\ZEngine\\Reflection\\ReflectionFunction('{$functionName}');
            \$ref->redefine(function (): string {
                return 'next-generation';
            });
            // Keep executing OLD opcodes after the swap: the live-frame guard must
            // keep the previous body allocated until this frame returns
            \$suffix = str_repeat('x', 4);

            return 'still-old-' . \$suffix;
        }
        PHP);

        assert(function_exists($functionName));
        // The in-flight call finishes on the old body, the next call dispatches the new one
        $this->assertSame('still-old-xxxx', $functionName());
        $this->assertSame('next-generation', $functionName());
    }

    public function testRedefineThroughCalleeKeepsTheCallerBodyAlive(): void
    {
        $functionName = 'zengine_caller_redefined_' . substr(md5(__METHOD__), 0, 8);
        eval(<<<PHP
        function {$functionName}(callable \$redefiner): string
        {
            // The callee redefines this very function while it is on the stack
            \$redefiner('{$functionName}');
            \$marker = strtoupper('alive');

            return 'old-' . \$marker;
        }
        PHP);

        assert(function_exists($functionName));
        $redefiner = static function (string $target): void {
            (new ReflectionFunction($target))->redefine(function (callable $redefiner): string {
                return 'new-body';
            });
        };
        $this->assertSame('old-ALIVE', $functionName($redefiner));
        $this->assertSame('new-body', $functionName($redefiner));
    }
}
