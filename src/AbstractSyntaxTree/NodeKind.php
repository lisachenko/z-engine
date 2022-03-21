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

use ZEngine\Constants\_zend_ast_kind;
use ZEngine\Constants\Defines;

/**
 * Declares possible AST nodes kind
 *
 * @see zend_ast.h:_zend_ast_kind
 */
class NodeKind
{
    private const AST_SPECIAL_SHIFT      = Defines::ZEND_AST_SPECIAL_SHIFT;
    private const AST_IS_LIST_SHIFT      = Defines::ZEND_AST_IS_LIST_SHIFT;
    private const AST_NUM_CHILDREN_SHIFT = Defines::ZEND_AST_NUM_CHILDREN_SHIFT;

    public const AST_ZVAL     =_zend_ast_kind::ZEND_AST_ZVAL;
    public const AST_CONSTANT =_zend_ast_kind::ZEND_AST_CONSTANT;
    public const AST_ZNODE    =_zend_ast_kind::ZEND_AST_ZNODE;

    /* declaration nodes */
    public const AST_FUNC_DECL  =_zend_ast_kind::ZEND_AST_FUNC_DECL;
    public const AST_CLOSURE    =_zend_ast_kind::ZEND_AST_CLOSURE;
    public const AST_METHOD     =_zend_ast_kind::ZEND_AST_METHOD;
    public const AST_CLASS      =_zend_ast_kind::ZEND_AST_CLASS;
    public const AST_ARROW_FUNC =_zend_ast_kind::ZEND_AST_ARROW_FUNC;

    /* list nodes */
    public const AST_ARG_LIST          =_zend_ast_kind::ZEND_AST_ARG_LIST;
    public const AST_ARRAY             =_zend_ast_kind::ZEND_AST_ARRAY;
    public const AST_ENCAPS_LIST       =_zend_ast_kind::ZEND_AST_ENCAPS_LIST;
    public const AST_EXPR_LIST         =_zend_ast_kind::ZEND_AST_EXPR_LIST;
    public const AST_STMT_LIST         =_zend_ast_kind::ZEND_AST_STMT_LIST;
    public const AST_IF                =_zend_ast_kind::ZEND_AST_IF;
    public const AST_SWITCH_LIST       =_zend_ast_kind::ZEND_AST_SWITCH_LIST;
    public const AST_CATCH_LIST        =_zend_ast_kind::ZEND_AST_CATCH_LIST;
    public const AST_PARAM_LIST        =_zend_ast_kind::ZEND_AST_PARAM_LIST;
    public const AST_CLOSURE_USES      =_zend_ast_kind::ZEND_AST_CLOSURE_USES;
    public const AST_PROP_DECL         =_zend_ast_kind::ZEND_AST_PROP_DECL;
    public const AST_CONST_DECL        =_zend_ast_kind::ZEND_AST_CONST_DECL;
    public const AST_CLASS_CONST_DECL  =_zend_ast_kind::ZEND_AST_CLASS_CONST_DECL;
    public const AST_NAME_LIST         =_zend_ast_kind::ZEND_AST_NAME_LIST;
    public const AST_TRAIT_ADAPTATIONS =_zend_ast_kind::ZEND_AST_TRAIT_ADAPTATIONS;
    public const AST_USE               =_zend_ast_kind::ZEND_AST_USE;

    /* 0 child nodes */
    public const AST_MAGIC_CONST    =_zend_ast_kind::ZEND_AST_MAGIC_CONST;
    public const AST_TYPE           =_zend_ast_kind::ZEND_AST_TYPE;
    public const AST_CONSTANT_CLASS =_zend_ast_kind::ZEND_AST_CONSTANT_CLASS;

    /* 1 child node */
    public const AST_VAR             =_zend_ast_kind::ZEND_AST_VAR;
    public const AST_CONST           =_zend_ast_kind::ZEND_AST_CONST;
    public const AST_UNPACK          =_zend_ast_kind::ZEND_AST_UNPACK;
    public const AST_UNARY_PLUS      =_zend_ast_kind::ZEND_AST_UNARY_PLUS;
    public const AST_UNARY_MINUS     =_zend_ast_kind::ZEND_AST_UNARY_MINUS;
    public const AST_CAST            =_zend_ast_kind::ZEND_AST_CAST;
    public const AST_EMPTY           =_zend_ast_kind::ZEND_AST_EMPTY;
    public const AST_ISSET           =_zend_ast_kind::ZEND_AST_ISSET;
    public const AST_SILENCE         =_zend_ast_kind::ZEND_AST_SILENCE;
    public const AST_SHELL_EXEC      =_zend_ast_kind::ZEND_AST_SHELL_EXEC;
    public const AST_CLONE           =_zend_ast_kind::ZEND_AST_CLONE;
    public const AST_EXIT            =_zend_ast_kind::ZEND_AST_EXIT;
    public const AST_PRINT           =_zend_ast_kind::ZEND_AST_PRINT;
    public const AST_INCLUDE_OR_EVAL =_zend_ast_kind::ZEND_AST_INCLUDE_OR_EVAL;
    public const AST_UNARY_OP        =_zend_ast_kind::ZEND_AST_UNARY_OP;
    public const AST_PRE_INC         =_zend_ast_kind::ZEND_AST_PRE_INC;
    public const AST_PRE_DEC         =_zend_ast_kind::ZEND_AST_PRE_DEC;
    public const AST_POST_INC        =_zend_ast_kind::ZEND_AST_POST_INC;
    public const AST_POST_DEC        =_zend_ast_kind::ZEND_AST_POST_DEC;
    public const AST_YIELD_FROM      =_zend_ast_kind::ZEND_AST_YIELD_FROM;
    public const AST_CLASS_NAME      =_zend_ast_kind::ZEND_AST_CLASS_NAME;

    public const AST_GLOBAL        =_zend_ast_kind::ZEND_AST_GLOBAL;
    public const AST_UNSET         =_zend_ast_kind::ZEND_AST_UNSET;
    public const AST_RETURN        =_zend_ast_kind::ZEND_AST_RETURN;
    public const AST_LABEL         =_zend_ast_kind::ZEND_AST_LABEL;
    public const AST_REF           =_zend_ast_kind::ZEND_AST_REF;
    public const AST_HALT_COMPILER =_zend_ast_kind::ZEND_AST_HALT_COMPILER;
    public const AST_ECHO          =_zend_ast_kind::ZEND_AST_ECHO;
    public const AST_THROW         =_zend_ast_kind::ZEND_AST_THROW;
    public const AST_GOTO          =_zend_ast_kind::ZEND_AST_GOTO;
    public const AST_BREAK         =_zend_ast_kind::ZEND_AST_BREAK;
    public const AST_CONTINUE      =_zend_ast_kind::ZEND_AST_CONTINUE;

    /* 2 child nodes */
    public const AST_DIM             =_zend_ast_kind::ZEND_AST_DIM;
    public const AST_PROP            =_zend_ast_kind::ZEND_AST_PROP;
    public const AST_STATIC_PROP     =_zend_ast_kind::ZEND_AST_STATIC_PROP;
    public const AST_CALL            =_zend_ast_kind::ZEND_AST_CALL;
    public const AST_CLASS_CONST     =_zend_ast_kind::ZEND_AST_CLASS_CONST;
    public const AST_ASSIGN          =_zend_ast_kind::ZEND_AST_ASSIGN;
    public const AST_ASSIGN_REF      =_zend_ast_kind::ZEND_AST_ASSIGN_REF;
    public const AST_ASSIGN_OP       =_zend_ast_kind::ZEND_AST_ASSIGN_OP;
    public const AST_BINARY_OP       =_zend_ast_kind::ZEND_AST_BINARY_OP;
    public const AST_GREATER         =_zend_ast_kind::ZEND_AST_GREATER;
    public const AST_GREATER_EQUAL   =_zend_ast_kind::ZEND_AST_GREATER_EQUAL;
    public const AST_AND             =_zend_ast_kind::ZEND_AST_AND;
    public const AST_OR              =_zend_ast_kind::ZEND_AST_OR;
    public const AST_ARRAY_ELEM      =_zend_ast_kind::ZEND_AST_ARRAY_ELEM;
    public const AST_NEW             =_zend_ast_kind::ZEND_AST_NEW;
    public const AST_INSTANCEOF      =_zend_ast_kind::ZEND_AST_INSTANCEOF;
    public const AST_YIELD           =_zend_ast_kind::ZEND_AST_YIELD;
    public const AST_COALESCE        =_zend_ast_kind::ZEND_AST_COALESCE;
    public const AST_ASSIGN_COALESCE =_zend_ast_kind::ZEND_AST_ASSIGN_COALESCE;

    public const AST_STATIC           =_zend_ast_kind::ZEND_AST_STATIC;
    public const AST_WHILE            =_zend_ast_kind::ZEND_AST_WHILE;
    public const AST_DO_WHILE         =_zend_ast_kind::ZEND_AST_DO_WHILE;
    public const AST_IF_ELEM          =_zend_ast_kind::ZEND_AST_IF_ELEM;
    public const AST_SWITCH           =_zend_ast_kind::ZEND_AST_SWITCH;
    public const AST_SWITCH_CASE      =_zend_ast_kind::ZEND_AST_SWITCH_CASE;
    public const AST_DECLARE          =_zend_ast_kind::ZEND_AST_DECLARE;
    public const AST_USE_TRAIT        =_zend_ast_kind::ZEND_AST_USE_TRAIT;
    public const AST_TRAIT_PRECEDENCE =_zend_ast_kind::ZEND_AST_TRAIT_PRECEDENCE;
    public const AST_METHOD_REFERENCE =_zend_ast_kind::ZEND_AST_METHOD_REFERENCE;
    public const AST_NAMESPACE        =_zend_ast_kind::ZEND_AST_NAMESPACE;
    public const AST_USE_ELEM         =_zend_ast_kind::ZEND_AST_USE_ELEM;
    public const AST_TRAIT_ALIAS      =_zend_ast_kind::ZEND_AST_TRAIT_ALIAS;
    public const AST_GROUP_USE        =_zend_ast_kind::ZEND_AST_GROUP_USE;
    public const AST_PROP_GROUP       =_zend_ast_kind::ZEND_AST_PROP_GROUP;

    /* 3 child nodes */
    public const AST_METHOD_CALL =_zend_ast_kind::ZEND_AST_METHOD_CALL;
    public const AST_STATIC_CALL =_zend_ast_kind::ZEND_AST_STATIC_CALL;
    public const AST_CONDITIONAL =_zend_ast_kind::ZEND_AST_CONDITIONAL;

    public const AST_TRY        =_zend_ast_kind::ZEND_AST_TRY;
    public const AST_CATCH      =_zend_ast_kind::ZEND_AST_CATCH;
    public const AST_PARAM      =_zend_ast_kind::ZEND_AST_PARAM;
    public const AST_PROP_ELEM  =_zend_ast_kind::ZEND_AST_PROP_ELEM;
    public const AST_CONST_ELEM =_zend_ast_kind::ZEND_AST_CONST_ELEM;

    /* 4 child nodes */
    public const AST_FOR =_zend_ast_kind::ZEND_AST_FOR;
    public const AST_FOREACH =_zend_ast_kind::ZEND_AST_FOREACH;

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
