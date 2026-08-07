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

    /**
     * The kind values are version-dependent: PHP 8.5 inserted AST_OP_ARRAY and
     * AST_CAST_VOID into enum _zend_ast_kind (shifting every later member), added
     * AST_PIPE and removed AST_CLONE/AST_EXIT. A kind that does not exist on the
     * running version is declared with a negative sentinel so it never matches a
     * real node and never collides in the reversed name() map.
     */
    private const IS_PHP85 = PHP_VERSION_ID >= 80500;

    /* Generated from enum _zend_ast_kind (8.4 and 8.5); EngineConstantsTest guards these values */
    public const AST_ZVAL                      = 64;
    public const AST_CONSTANT                  = 65;
    public const AST_OP_ARRAY                  = self::IS_PHP85 ? 66 : -66; // PHP 8.5+
    public const AST_ZNODE                     = self::IS_PHP85 ? 67 : 66;
    public const AST_FUNC_DECL                 = self::IS_PHP85 ? 68 : 67;
    public const AST_CLOSURE                   = self::IS_PHP85 ? 69 : 68;
    public const AST_METHOD                    = self::IS_PHP85 ? 70 : 69;
    public const AST_CLASS                     = self::IS_PHP85 ? 71 : 70;
    public const AST_ARROW_FUNC                = self::IS_PHP85 ? 72 : 71;
    public const AST_PROPERTY_HOOK             = self::IS_PHP85 ? 73 : 72;
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
    public const AST_CAST_VOID                 = self::IS_PHP85 ? 262 : -262; // PHP 8.5+
    public const AST_EMPTY                     = self::IS_PHP85 ? 263 : 262;
    public const AST_ISSET                     = self::IS_PHP85 ? 264 : 263;
    public const AST_SILENCE                   = self::IS_PHP85 ? 265 : 264;
    public const AST_SHELL_EXEC                = self::IS_PHP85 ? 266 : 265;
    public const AST_CLONE                     = self::IS_PHP85 ? -266 : 266; // kind removed in PHP 8.5
    public const AST_EXIT                      = self::IS_PHP85 ? -267 : 267; // kind removed in PHP 8.5
    public const AST_PRINT                     = self::IS_PHP85 ? 267 : 268;
    public const AST_INCLUDE_OR_EVAL           = self::IS_PHP85 ? 268 : 269;
    public const AST_UNARY_OP                  = self::IS_PHP85 ? 269 : 270;
    public const AST_PRE_INC                   = self::IS_PHP85 ? 270 : 271;
    public const AST_PRE_DEC                   = self::IS_PHP85 ? 271 : 272;
    public const AST_POST_INC                  = self::IS_PHP85 ? 272 : 273;
    public const AST_POST_DEC                  = self::IS_PHP85 ? 273 : 274;
    public const AST_YIELD_FROM                = self::IS_PHP85 ? 274 : 275;
    public const AST_CLASS_NAME                = self::IS_PHP85 ? 275 : 276;
    public const AST_GLOBAL                    = self::IS_PHP85 ? 276 : 277;
    public const AST_UNSET                     = self::IS_PHP85 ? 277 : 278;
    public const AST_RETURN                    = self::IS_PHP85 ? 278 : 279;
    public const AST_LABEL                     = self::IS_PHP85 ? 279 : 280;
    public const AST_REF                       = self::IS_PHP85 ? 280 : 281;
    public const AST_HALT_COMPILER             = self::IS_PHP85 ? 281 : 282;
    public const AST_ECHO                      = self::IS_PHP85 ? 282 : 283;
    public const AST_THROW                     = self::IS_PHP85 ? 283 : 284;
    public const AST_GOTO                      = self::IS_PHP85 ? 284 : 285;
    public const AST_BREAK                     = self::IS_PHP85 ? 285 : 286;
    public const AST_CONTINUE                  = self::IS_PHP85 ? 286 : 287;
    public const AST_PROPERTY_HOOK_SHORT_BODY  = self::IS_PHP85 ? 287 : 288;
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
    public const AST_PIPE                      = self::IS_PHP85 ? 551 : -551; // PHP 8.5+
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
            // Only the public AST_* kinds take part in the reverse map: the private
            // helpers and the negative other-version sentinels are not node kinds
            foreach ((new \ReflectionClass(self::class))->getConstants(\ReflectionClassConstant::IS_PUBLIC) as $name => $value) {
                if (is_int($value) && $value >= 0) {
                    self::$constantNames[$value] = $name;
                }
            }
        }

        if (!isset(self::$constantNames[$astKind])) {
            throw new \UnexpectedValueException('Unknown code ' . $astKind . '. New version of PHP?');
        }

        return self::$constantNames[$astKind];
    }
}
