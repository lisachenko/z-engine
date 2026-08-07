<?php

/**
 * Fixture compiled into the opcache file cache by the OpCache API tests.
 *
 * Deliberately exercises walker breadth: a global function, a class with a
 * constant, a typed property with a default, a static variable, an attribute,
 * a try/catch block and a string literal that patch tests rewrite. This file
 * must stay parsable on every supported PHP minor; the node shapes PHP 8.5
 * introduced into the file-cache format use 8.5-only syntax and live in
 * answer-php85.php, compiled by Php85SerializerShapesTest only.
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
