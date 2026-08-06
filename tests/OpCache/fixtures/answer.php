<?php

/**
 * Fixture compiled into the opcache file cache by the OpCache API tests.
 *
 * Deliberately exercises walker breadth: a global function, a class with a
 * constant, a typed property with a default, a static variable, an attribute,
 * a try/catch block and a string literal that patch tests rewrite, plus the two
 * node shapes PHP 8.5 introduced into the file-cache format (see below).
 */
declare(strict_types=1);

function zengine_bin_answer(): int
{
    return 41;
}

function zengine_bin_greeting(): string
{
    return 'hello';
}

#[\Attribute(\Attribute::TARGET_CLASS)]
class ZEngineBinMarker {}

#[ZEngineBinMarker]
class ZEngineBinSubject
{
    public const CHANNEL = 'stable';

    public int $counter = 7;

    public static function describe(int $times): string
    {
        static $calls = 0;
        ++$calls;
        try {
            return str_repeat(self::CHANNEL, max(1, $times));
        } catch (\Throwable $error) {
            return $error->getMessage();
        }
    }
}

#[\Attribute(\Attribute::TARGET_ALL)]
class ZEngineBinConstMarker {}

/*
 * PHP 8.5 shape #1: an attributed global constant compiles to
 * ZEND_DECLARE_ATTRIBUTED_CONST + ZEND_OP_DATA, whose IS_CONST operand points at
 * a literal of type IS_PTR holding the attribute table. The literal walk skips
 * IS_PTR zvals, so the table is only reachable through the opline pair.
 */
#[ZEngineBinConstMarker]
const ZENGINE_BIN_FLAG = 'on';

/*
 * PHP 8.5 shape #2: a static closure inside a constant expression compiles to a
 * ZEND_AST_OP_ARRAY node, which carries a zend_op_array pointer instead of the
 * child pointers the generic AST walk expects.
 */
const ZENGINE_BIN_CALLBACK = static function (): int {
    return 5;
};
