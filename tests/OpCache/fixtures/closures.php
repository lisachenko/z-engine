<?php

/**
 * Fixture compiled into the opcache file cache by the closure relocation tests.
 *
 * Exercises the dynamic_func_defs walk of zend_file_cache_(un)serialize_op_array:
 * an arrow function and an anonymous function inside a global function, a
 * closure nested inside another closure (recursion into a nested def's own
 * dynamic_func_defs), and a scoped arrow function inside a static method.
 */
declare(strict_types=1);

class ZEngineClosureHost
{
    public static function tally(): int
    {
        $add = fn(int $a, int $b): int => $a + $b;

        return $add(40, 2);
    }
}

function zengine_bin_closures_run(): string
{
    $factor = 3;
    $arrow  = fn(int $value): int => $value * $factor;
    $anon   = function (string $word) use ($factor): string {
        $inner = fn(): int => $factor + 1;

        return str_repeat($word, $inner() - $factor);
    };

    return $anon('cl') . ':' . $arrow(14) . ':' . ZEngineClosureHost::tally() . ':cl-ok';
}
