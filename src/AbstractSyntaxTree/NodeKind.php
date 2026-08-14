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

use ZEngine\Type\ConstantNames;

/**
 * Declares possible AST nodes kind
 *
 * @see zend_ast.h:_zend_ast_kind
 */
class NodeKind
{
    private const int AST_SPECIAL_SHIFT      = 6;
    private const int AST_IS_LIST_SHIFT      = 7;
    private const int AST_NUM_CHILDREN_SHIFT = 8;

    /* Generated for PHP 8.5 from enum _zend_ast_kind; EngineConstantsTest guards these values */
    public const int AST_ZVAL                      = 64;
    public const int AST_CONSTANT                  = 65;
    public const int AST_OP_ARRAY                  = 66;
    public const int AST_ZNODE                     = 67;
    public const int AST_FUNC_DECL                 = 68;
    public const int AST_CLOSURE                   = 69;
    public const int AST_METHOD                    = 70;
    public const int AST_CLASS                     = 71;
    public const int AST_ARROW_FUNC                = 72;
    public const int AST_PROPERTY_HOOK             = 73;
    public const int AST_ARG_LIST                  = 128;
    public const int AST_ARRAY                     = 129;
    public const int AST_ENCAPS_LIST               = 130;
    public const int AST_EXPR_LIST                 = 131;
    public const int AST_STMT_LIST                 = 132;
    public const int AST_IF                        = 133;
    public const int AST_SWITCH_LIST               = 134;
    public const int AST_CATCH_LIST                = 135;
    public const int AST_PARAM_LIST                = 136;
    public const int AST_CLOSURE_USES              = 137;
    public const int AST_PROP_DECL                 = 138;
    public const int AST_CONST_DECL                = 139;
    public const int AST_CLASS_CONST_DECL          = 140;
    public const int AST_NAME_LIST                 = 141;
    public const int AST_TRAIT_ADAPTATIONS         = 142;
    public const int AST_USE                       = 143;
    public const int AST_TYPE_UNION                = 144;
    public const int AST_TYPE_INTERSECTION         = 145;
    public const int AST_ATTRIBUTE_LIST            = 146;
    public const int AST_ATTRIBUTE_GROUP           = 147;
    public const int AST_MATCH_ARM_LIST            = 148;
    public const int AST_MODIFIER_LIST             = 149;
    public const int AST_MAGIC_CONST               = 0;
    public const int AST_TYPE                      = 1;
    public const int AST_CONSTANT_CLASS            = 2;
    public const int AST_CALLABLE_CONVERT          = 3;
    public const int AST_VAR                       = 256;
    public const int AST_CONST                     = 257;
    public const int AST_UNPACK                    = 258;
    public const int AST_UNARY_PLUS                = 259;
    public const int AST_UNARY_MINUS               = 260;
    public const int AST_CAST                      = 261;
    public const int AST_CAST_VOID                 = 262;
    public const int AST_EMPTY                     = 263;
    public const int AST_ISSET                     = 264;
    public const int AST_SILENCE                   = 265;
    public const int AST_SHELL_EXEC                = 266;
    public const int AST_PRINT                     = 267;
    public const int AST_INCLUDE_OR_EVAL           = 268;
    public const int AST_UNARY_OP                  = 269;
    public const int AST_PRE_INC                   = 270;
    public const int AST_PRE_DEC                   = 271;
    public const int AST_POST_INC                  = 272;
    public const int AST_POST_DEC                  = 273;
    public const int AST_YIELD_FROM                = 274;
    public const int AST_CLASS_NAME                = 275;
    public const int AST_GLOBAL                    = 276;
    public const int AST_UNSET                     = 277;
    public const int AST_RETURN                    = 278;
    public const int AST_LABEL                     = 279;
    public const int AST_REF                       = 280;
    public const int AST_HALT_COMPILER             = 281;
    public const int AST_ECHO                      = 282;
    public const int AST_THROW                     = 283;
    public const int AST_GOTO                      = 284;
    public const int AST_BREAK                     = 285;
    public const int AST_CONTINUE                  = 286;
    public const int AST_PROPERTY_HOOK_SHORT_BODY  = 287;
    public const int AST_DIM                       = 512;
    public const int AST_PROP                      = 513;
    public const int AST_NULLSAFE_PROP             = 514;
    public const int AST_STATIC_PROP               = 515;
    public const int AST_CALL                      = 516;
    public const int AST_CLASS_CONST               = 517;
    public const int AST_ASSIGN                    = 518;
    public const int AST_ASSIGN_REF                = 519;
    public const int AST_ASSIGN_OP                 = 520;
    public const int AST_BINARY_OP                 = 521;
    public const int AST_GREATER                   = 522;
    public const int AST_GREATER_EQUAL             = 523;
    public const int AST_AND                       = 524;
    public const int AST_OR                        = 525;
    public const int AST_ARRAY_ELEM                = 526;
    public const int AST_NEW                       = 527;
    public const int AST_INSTANCEOF                = 528;
    public const int AST_YIELD                     = 529;
    public const int AST_COALESCE                  = 530;
    public const int AST_ASSIGN_COALESCE           = 531;
    public const int AST_STATIC                    = 532;
    public const int AST_WHILE                     = 533;
    public const int AST_DO_WHILE                  = 534;
    public const int AST_IF_ELEM                   = 535;
    public const int AST_SWITCH                    = 536;
    public const int AST_SWITCH_CASE               = 537;
    public const int AST_DECLARE                   = 538;
    public const int AST_USE_TRAIT                 = 539;
    public const int AST_TRAIT_PRECEDENCE          = 540;
    public const int AST_METHOD_REFERENCE          = 541;
    public const int AST_NAMESPACE                 = 542;
    public const int AST_USE_ELEM                  = 543;
    public const int AST_TRAIT_ALIAS               = 544;
    public const int AST_GROUP_USE                 = 545;
    public const int AST_ATTRIBUTE                 = 546;
    public const int AST_MATCH                     = 547;
    public const int AST_MATCH_ARM                 = 548;
    public const int AST_NAMED_ARG                 = 549;
    public const int AST_PARENT_PROPERTY_HOOK_CALL = 550;
    public const int AST_PIPE                      = 551;
    public const int AST_METHOD_CALL               = 768;
    public const int AST_NULLSAFE_METHOD_CALL      = 769;
    public const int AST_STATIC_CALL               = 770;
    public const int AST_CONDITIONAL               = 771;
    public const int AST_TRY                       = 772;
    public const int AST_CATCH                     = 773;
    public const int AST_PROP_GROUP                = 774;
    public const int AST_CONST_ELEM                = 775;
    public const int AST_CLASS_CONST_GROUP         = 776;
    public const int AST_CONST_ENUM_INIT           = 777;
    public const int AST_FOR                       = 1024;
    public const int AST_FOREACH                   = 1025;
    public const int AST_ENUM_CASE                 = 1026;
    public const int AST_PROP_ELEM                 = 1027;
    public const int AST_PARAM                     = 1536;

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
        // Only the public AST_* kinds are engine values: the private AST_*_SHIFT constants
        // used to end up in the reversed map and were reported as if they were node kinds
        $constantNames = ConstantNames::of(self::class, 'AST_');
        if (!isset($constantNames[$astKind])) {
            throw new \UnexpectedValueException('Unknown code ' . $astKind . '. New version of PHP?');
        }

        return $constantNames[$astKind];
    }
}
