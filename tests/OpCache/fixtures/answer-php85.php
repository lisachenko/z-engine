<?php

/**
 * Fixture for the node shapes PHP 8.5 introduced into the file-cache format.
 *
 * This file uses 8.5-only syntax and therefore does not parse on PHP 8.4 - it is
 * compiled exclusively by the version-gated Php85SerializerShapesTest, never by
 * the shared fixture path of FileCacheFixture.
 */
declare(strict_types=1);

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

function zengine_bin_php85(): string
{
    return ZENGINE_BIN_FLAG . (ZENGINE_BIN_CALLBACK)();
}
