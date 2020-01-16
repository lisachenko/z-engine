<?php
/**
 * Z-Engine framework
 *
 * @copyright Copyright 2020, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\AbstractSyntaxTree;

/**
 * Declares possible AST nodes kind
 *
 * @see zend_ast.h:_zend_ast_kind
 */
class NodeKind
{
    private const AST_SPECIAL_SHIFT      = 6;
    private const AST_IS_LIST_SHIFT      = 7;
    private const AST_NUM_CHILDREN_SHIFT = 8;

    public const AST_ZVAL     = 1 << self::AST_SPECIAL_SHIFT;
    public const AST_CONSTANT = self::AST_ZVAL + 1;
    public const AST_ZNODE    = self::AST_ZVAL + 2;

    /* declaration nodes */
    public const AST_FUNC_DECL  = self::AST_ZVAL + 3;
    public const AST_CLOSURE    = self::AST_ZVAL + 4;
    public const AST_METHOD     = self::AST_ZVAL + 5;
    public const AST_CLASS      = self::AST_ZVAL + 6;
    public const AST_ARROW_FUNC = self::AST_ZVAL + 7;

    /* list nodes */
    public const AST_ARG_LIST          = 1 << self::AST_IS_LIST_SHIFT;
    public const AST_ARRAY             = self::AST_ARG_LIST + 1;
    public const AST_ENCAPS_LIST       = self::AST_ARG_LIST + 2;
    public const AST_EXPR_LIST         = self::AST_ARG_LIST + 3;
    public const AST_STMT_LIST         = self::AST_ARG_LIST + 4;
    public const AST_IF                = self::AST_ARG_LIST + 5;
    public const AST_SWITCH_LIST       = self::AST_ARG_LIST + 6;
    public const AST_CATCH_LIST        = self::AST_ARG_LIST + 7;
    public const AST_PARAM_LIST        = self::AST_ARG_LIST + 8;
    public const AST_CLOSURE_USES      = self::AST_ARG_LIST + 9;
    public const AST_PROP_DECL         = self::AST_ARG_LIST + 10;
    public const AST_CONST_DECL        = self::AST_ARG_LIST + 11;
    public const AST_CLASS_CONST_DECL  = self::AST_ARG_LIST + 12;
    public const AST_NAME_LIST         = self::AST_ARG_LIST + 13;
    public const AST_TRAIT_ADAPTATIONS = self::AST_ARG_LIST + 14;
    public const AST_USE               = self::AST_ARG_LIST + 15;

    /* 0 child nodes */
    public const AST_MAGIC_CONST    = 0 << self::AST_NUM_CHILDREN_SHIFT;
    public const AST_TYPE           = self::AST_MAGIC_CONST + 1;
    public const AST_CONSTANT_CLASS = self::AST_MAGIC_CONST + 2;

    /* 1 child node */
    public const AST_VAR             = 1 << self::AST_NUM_CHILDREN_SHIFT;
    public const AST_CONST           = self::AST_VAR + 1;
    public const AST_UNPACK          = self::AST_VAR + 2;
    public const AST_UNARY_PLUS      = self::AST_VAR + 3;
    public const AST_UNARY_MINUS     = self::AST_VAR + 4;
    public const AST_CAST            = self::AST_VAR + 5;
    public const AST_EMPTY           = self::AST_VAR + 6;
    public const AST_ISSET           = self::AST_VAR + 7;
    public const AST_SILENCE         = self::AST_VAR + 8;
    public const AST_SHELL_EXEC      = self::AST_VAR + 9;
    public const AST_CLONE           = self::AST_VAR + 10;
    public const AST_EXIT            = self::AST_VAR + 11;
    public const AST_PRINT           = self::AST_VAR + 12;
    public const AST_INCLUDE_OR_EVAL = self::AST_VAR + 13;
    public const AST_UNARY_OP        = self::AST_VAR + 14;
    public const AST_PRE_INC         = self::AST_VAR + 15;
    public const AST_PRE_DEC         = self::AST_VAR + 16;
    public const AST_POST_INC        = self::AST_VAR + 17;
    public const AST_POST_DEC        = self::AST_VAR + 18;
    public const AST_YIELD_FROM      = self::AST_VAR + 19;
    public const AST_CLASS_NAME      = self::AST_VAR + 20;

    public const AST_GLOBAL        = self::AST_VAR + 21;
    public const AST_UNSET         = self::AST_VAR + 22;
    public const AST_RETURN        = self::AST_VAR + 23;
    public const AST_LABEL         = self::AST_VAR + 24;
    public const AST_REF           = self::AST_VAR + 25;
    public const AST_HALT_COMPILER = self::AST_VAR + 26;
    public const AST_ECHO          = self::AST_VAR + 27;
    public const AST_THROW         = self::AST_VAR + 28;
    public const AST_GOTO          = self::AST_VAR + 29;
    public const AST_BREAK         = self::AST_VAR + 30;
    public const AST_CONTINUE      = self::AST_VAR + 31;

    /* 2 child nodes */
    public const AST_DIM             = 2 << self::AST_NUM_CHILDREN_SHIFT;
    public const AST_PROP            = self::AST_DIM + 1;
    public const AST_STATIC_PROP     = self::AST_DIM + 2;
    public const AST_CALL            = self::AST_DIM + 3;
    public const AST_CLASS_CONST     = self::AST_DIM + 4;
    public const AST_ASSIGN          = self::AST_DIM + 5;
    public const AST_ASSIGN_REF      = self::AST_DIM + 6;
    public const AST_ASSIGN_OP       = self::AST_DIM + 7;
    public const AST_BINARY_OP       = self::AST_DIM + 8;
    public const AST_GREATER         = self::AST_DIM + 9;
    public const AST_GREATER_EQUAL   = self::AST_DIM + 10;
    public const AST_AND             = self::AST_DIM + 11;
    public const AST_OR              = self::AST_DIM + 12;
    public const AST_ARRAY_ELEM      = self::AST_DIM + 13;
    public const AST_NEW             = self::AST_DIM + 14;
    public const AST_INSTANCEOF      = self::AST_DIM + 15;
    public const AST_YIELD           = self::AST_DIM + 16;
    public const AST_COALESCE        = self::AST_DIM + 17;
    public const AST_ASSIGN_COALESCE = self::AST_DIM + 18;

    public const AST_STATIC           = self::AST_DIM + 19;
    public const AST_WHILE            = self::AST_DIM + 20;
    public const AST_DO_WHILE         = self::AST_DIM + 21;
    public const AST_IF_ELEM          = self::AST_DIM + 22;
    public const AST_SWITCH           = self::AST_DIM + 23;
    public const AST_SWITCH_CASE      = self::AST_DIM + 24;
    public const AST_DECLARE          = self::AST_DIM + 25;
    public const AST_USE_TRAIT        = self::AST_DIM + 26;
    public const AST_TRAIT_PRECEDENCE = self::AST_DIM + 27;
    public const AST_METHOD_REFERENCE = self::AST_DIM + 28;
    public const AST_NAMESPACE        = self::AST_DIM + 29;
    public const AST_USE_ELEM         = self::AST_DIM + 30;
    public const AST_TRAIT_ALIAS      = self::AST_DIM + 31;
    public const AST_GROUP_USE        = self::AST_DIM + 32;
    public const AST_PROP_GROUP       = self::AST_DIM + 33;

    /* 3 child nodes */
    public const AST_METHOD_CALL = 3 << self::AST_NUM_CHILDREN_SHIFT;
    public const AST_STATIC_CALL = self::AST_METHOD_CALL + 1;
    public const AST_CONDITIONAL = self::AST_METHOD_CALL + 2;

    public const AST_TRY        = self::AST_METHOD_CALL + 3;
    public const AST_CATCH      = self::AST_METHOD_CALL + 4;
    public const AST_PARAM      = self::AST_METHOD_CALL + 5;
    public const AST_PROP_ELEM  = self::AST_METHOD_CALL + 6;
    public const AST_CONST_ELEM = self::AST_METHOD_CALL + 7;

    /* 4 child nodes */
    public const AST_FOR = 4 << self::AST_NUM_CHILDREN_SHIFT;
    public const AST_FOREACH = self::AST_FOR + 1;

    /**
     * Cache of constant names (reversed)
     *
     * @var string[]
     */
    private static array $constantNames = [];

    /**
     * Checks if the given AST node kind is special
     *
     * @param int $astKind Kind of node
     *
     * @see zend_ast.h:zend_ast_is_special
     */
    public static function isSpecial(int $astKind): bool
    {
        return (bool)(($astKind >> self::AST_SPECIAL_SHIFT) & 1);
    }

    /**
     * Checks if the given AST node kind is list
     *
     * @param int $astKind Kind of node
     *
     * @see zend_ast.h:zend_ast_is_list
     */
    public static function isList(int $astKind): bool
    {
        return (bool)(($astKind >> self::AST_IS_LIST_SHIFT) & 1);
    }

    /**
     * Returns the number of children for that node
     *
     * @param int $astKind Kind of node
     */
    public static function childrenCount(int $astKind): int
    {
        return $astKind >> self::AST_NUM_CHILDREN_SHIFT;
    }

    /**
     * Returns the AST kind name
     *
     * @param int $astKind Integer value of AST node kind
     */
    public static function name(int $astKind): string
    {
        if (empty(self::$constantNames)) {
            self::$constantNames = array_flip((new \ReflectionClass(self::class))->getConstants());
        }

        if (!isset(self::$constantNames[$astKind])) {
            throw new \UnexpectedValueException('Unknown code ' . $astKind . '. New version of PHP?');
        }

        return self::$constantNames[$astKind];
    }
}
