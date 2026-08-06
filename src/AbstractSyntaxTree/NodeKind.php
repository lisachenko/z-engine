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

    /* Generated for PHP 8.5 from enum _zend_ast_kind; EngineConstantsTest guards these values */
    public const AST_ZVAL                      = 64;
    public const AST_CONSTANT                  = 65;
    public const AST_OP_ARRAY                  = 66;
    public const AST_ZNODE                     = 67;
    public const AST_FUNC_DECL                 = 68;
    public const AST_CLOSURE                   = 69;
    public const AST_METHOD                    = 70;
    public const AST_CLASS                     = 71;
    public const AST_ARROW_FUNC                = 72;
    public const AST_PROPERTY_HOOK             = 73;
    public const AST_ARG_LIST                  = 128;
    public const AST_ARRAY                     = 129;
    public const AST_ENCAPS_LIST               = 130;
    public const AST_EXPR_LIST                 = 131;
    public const AST_STMT_LIST                 = 132;
    public const AST_IF                        = 133;
    public const AST_SWITCH_LIST               = 134;
    public const AST_CATCH_LIST                = 135;
    public const AST_PARAM_LIST                = 136;
    public const AST_CLOSURE_USES              = 137;
    public const AST_PROP_DECL                 = 138;
    public const AST_CONST_DECL                = 139;
    public const AST_CLASS_CONST_DECL          = 140;
    public const AST_NAME_LIST                 = 141;
    public const AST_TRAIT_ADAPTATIONS         = 142;
    public const AST_USE                       = 143;
    public const AST_TYPE_UNION                = 144;
    public const AST_TYPE_INTERSECTION         = 145;
    public const AST_ATTRIBUTE_LIST            = 146;
    public const AST_ATTRIBUTE_GROUP           = 147;
    public const AST_MATCH_ARM_LIST            = 148;
    public const AST_MODIFIER_LIST             = 149;
    public const AST_MAGIC_CONST               = 0;
    public const AST_TYPE                      = 1;
    public const AST_CONSTANT_CLASS            = 2;
    public const AST_CALLABLE_CONVERT          = 3;
    public const AST_VAR                       = 256;
    public const AST_CONST                     = 257;
    public const AST_UNPACK                    = 258;
    public const AST_UNARY_PLUS                = 259;
    public const AST_UNARY_MINUS               = 260;
    public const AST_CAST                      = 261;
    public const AST_CAST_VOID                 = 262;
    public const AST_EMPTY                     = 263;
    public const AST_ISSET                     = 264;
    public const AST_SILENCE                   = 265;
    public const AST_SHELL_EXEC                = 266;
    public const AST_PRINT                     = 267;
    public const AST_INCLUDE_OR_EVAL           = 268;
    public const AST_UNARY_OP                  = 269;
    public const AST_PRE_INC                   = 270;
    public const AST_PRE_DEC                   = 271;
    public const AST_POST_INC                  = 272;
    public const AST_POST_DEC                  = 273;
    public const AST_YIELD_FROM                = 274;
    public const AST_CLASS_NAME                = 275;
    public const AST_GLOBAL                    = 276;
    public const AST_UNSET                     = 277;
    public const AST_RETURN                    = 278;
    public const AST_LABEL                     = 279;
    public const AST_REF                       = 280;
    public const AST_HALT_COMPILER             = 281;
    public const AST_ECHO                      = 282;
    public const AST_THROW                     = 283;
    public const AST_GOTO                      = 284;
    public const AST_BREAK                     = 285;
    public const AST_CONTINUE                  = 286;
    public const AST_PROPERTY_HOOK_SHORT_BODY  = 287;
    public const AST_DIM                       = 512;
    public const AST_PROP                      = 513;
    public const AST_NULLSAFE_PROP             = 514;
    public const AST_STATIC_PROP               = 515;
    public const AST_CALL                      = 516;
    public const AST_CLASS_CONST               = 517;
    public const AST_ASSIGN                    = 518;
    public const AST_ASSIGN_REF                = 519;
    public const AST_ASSIGN_OP                 = 520;
    public const AST_BINARY_OP                 = 521;
    public const AST_GREATER                   = 522;
    public const AST_GREATER_EQUAL             = 523;
    public const AST_AND                       = 524;
    public const AST_OR                        = 525;
    public const AST_ARRAY_ELEM                = 526;
    public const AST_NEW                       = 527;
    public const AST_INSTANCEOF                = 528;
    public const AST_YIELD                     = 529;
    public const AST_COALESCE                  = 530;
    public const AST_ASSIGN_COALESCE           = 531;
    public const AST_STATIC                    = 532;
    public const AST_WHILE                     = 533;
    public const AST_DO_WHILE                  = 534;
    public const AST_IF_ELEM                   = 535;
    public const AST_SWITCH                    = 536;
    public const AST_SWITCH_CASE               = 537;
    public const AST_DECLARE                   = 538;
    public const AST_USE_TRAIT                 = 539;
    public const AST_TRAIT_PRECEDENCE          = 540;
    public const AST_METHOD_REFERENCE          = 541;
    public const AST_NAMESPACE                 = 542;
    public const AST_USE_ELEM                  = 543;
    public const AST_TRAIT_ALIAS               = 544;
    public const AST_GROUP_USE                 = 545;
    public const AST_ATTRIBUTE                 = 546;
    public const AST_MATCH                     = 547;
    public const AST_MATCH_ARM                 = 548;
    public const AST_NAMED_ARG                 = 549;
    public const AST_PARENT_PROPERTY_HOOK_CALL = 550;
    public const AST_PIPE                      = 551;
    public const AST_METHOD_CALL               = 768;
    public const AST_NULLSAFE_METHOD_CALL      = 769;
    public const AST_STATIC_CALL               = 770;
    public const AST_CONDITIONAL               = 771;
    public const AST_TRY                       = 772;
    public const AST_CATCH                     = 773;
    public const AST_PROP_GROUP                = 774;
    public const AST_CONST_ELEM                = 775;
    public const AST_CLASS_CONST_GROUP         = 776;
    public const AST_CONST_ENUM_INIT           = 777;
    public const AST_FOR                       = 1024;
    public const AST_FOREACH                   = 1025;
    public const AST_ENUM_CASE                 = 1026;
    public const AST_PROP_ELEM                 = 1027;
    public const AST_PARAM                     = 1536;

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
        return (bool) (($astKind >> self::AST_SPECIAL_SHIFT) & 1);
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
        return (bool) (($astKind >> self::AST_IS_LIST_SHIFT) & 1);
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
