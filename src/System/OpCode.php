<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\System;

use Closure;
use ZEngine\Core;
use ZEngine\System\Hook\OpCodeHook;
use ZEngine\Type\ConstantNames;

/**
 * Hold all internal opcode constants and provide an API to hook any existing opcode
 */
final class OpCode
{
    public const int NOP                            = 0;
    public const int ADD                            = 1;
    public const int SUB                            = 2;
    public const int MUL                            = 3;
    public const int DIV                            = 4;
    public const int MOD                            = 5;
    public const int SL                             = 6;
    public const int SR                             = 7;
    public const int CONCAT                         = 8;
    public const int BW_OR                          = 9;
    public const int BW_AND                         = 10;
    public const int BW_XOR                         = 11;
    public const int POW                            = 12;
    public const int BW_NOT                         = 13;
    public const int BOOL_NOT                       = 14;
    public const int BOOL_XOR                       = 15;
    public const int IS_IDENTICAL                   = 16;
    public const int IS_NOT_IDENTICAL               = 17;
    public const int IS_EQUAL                       = 18;
    public const int IS_NOT_EQUAL                   = 19;
    public const int IS_SMALLER                     = 20;
    public const int IS_SMALLER_OR_EQUAL            = 21;
    public const int ASSIGN                         = 22;
    public const int ASSIGN_DIM                     = 23;
    public const int ASSIGN_OBJ                     = 24;
    public const int ASSIGN_STATIC_PROP             = 25;
    public const int ASSIGN_OP                      = 26;
    public const int ASSIGN_DIM_OP                  = 27;
    public const int ASSIGN_OBJ_OP                  = 28;
    public const int ASSIGN_STATIC_PROP_OP          = 29;
    public const int ASSIGN_REF                     = 30;
    public const int QM_ASSIGN                      = 31;
    public const int ASSIGN_OBJ_REF                 = 32;
    public const int ASSIGN_STATIC_PROP_REF         = 33;
    public const int PRE_INC                        = 34;
    public const int PRE_DEC                        = 35;
    public const int POST_INC                       = 36;
    public const int POST_DEC                       = 37;
    public const int PRE_INC_STATIC_PROP            = 38;
    public const int PRE_DEC_STATIC_PROP            = 39;
    public const int POST_INC_STATIC_PROP           = 40;
    public const int POST_DEC_STATIC_PROP           = 41;
    public const int JMP                            = 42;
    public const int JMPZ                           = 43;
    public const int JMPNZ                          = 44;
    public const int JMPZ_EX                        = 46;
    public const int JMPNZ_EX                       = 47;
    public const int CASE                           = 48;
    public const int CHECK_VAR                      = 49;
    public const int SEND_VAR_NO_REF_EX             = 50;
    public const int CAST                           = 51;
    public const int BOOL                           = 52;
    public const int FAST_CONCAT                    = 53;
    public const int ROPE_INIT                      = 54;
    public const int ROPE_ADD                       = 55;
    public const int ROPE_END                       = 56;
    public const int BEGIN_SILENCE                  = 57;
    public const int END_SILENCE                    = 58;
    public const int INIT_FCALL_BY_NAME             = 59;
    public const int DO_FCALL                       = 60;
    public const int INIT_FCALL                     = 61;
    public const int RETURN                         = 62;
    public const int RECV                           = 63;
    public const int RECV_INIT                      = 64;
    public const int SEND_VAL                       = 65;
    public const int SEND_VAR_EX                    = 66;
    public const int SEND_REF                       = 67;
    public const int NEW                            = 68;
    public const int INIT_NS_FCALL_BY_NAME          = 69;
    public const int FREE                           = 70;
    public const int INIT_ARRAY                     = 71;
    public const int ADD_ARRAY_ELEMENT              = 72;
    public const int INCLUDE_OR_EVAL                = 73;
    public const int UNSET_VAR                      = 74;
    public const int UNSET_DIM                      = 75;
    public const int UNSET_OBJ                      = 76;
    public const int FE_RESET_R                     = 77;
    public const int FE_FETCH_R                     = 78;
    public const int FETCH_R                        = 80;
    public const int FETCH_DIM_R                    = 81;
    public const int FETCH_OBJ_R                    = 82;
    public const int FETCH_W                        = 83;
    public const int FETCH_DIM_W                    = 84;
    public const int FETCH_OBJ_W                    = 85;
    public const int FETCH_RW                       = 86;
    public const int FETCH_DIM_RW                   = 87;
    public const int FETCH_OBJ_RW                   = 88;
    public const int FETCH_IS                       = 89;
    public const int FETCH_DIM_IS                   = 90;
    public const int FETCH_OBJ_IS                   = 91;
    public const int FETCH_FUNC_ARG                 = 92;
    public const int FETCH_DIM_FUNC_ARG             = 93;
    public const int FETCH_OBJ_FUNC_ARG             = 94;
    public const int FETCH_UNSET                    = 95;
    public const int FETCH_DIM_UNSET                = 96;
    public const int FETCH_OBJ_UNSET                = 97;
    public const int FETCH_LIST_R                   = 98;
    public const int FETCH_CONSTANT                 = 99;
    public const int CHECK_FUNC_ARG                 = 100;
    public const int EXT_STMT                       = 101;
    public const int EXT_FCALL_BEGIN                = 102;
    public const int EXT_FCALL_END                  = 103;
    public const int EXT_NOP                        = 104;
    public const int TICKS                          = 105;
    public const int SEND_VAR_NO_REF                = 106;
    public const int CATCH                          = 107;
    public const int THROW                          = 108;
    public const int FETCH_CLASS                    = 109;
    public const int CLONE                          = 110;
    public const int RETURN_BY_REF                  = 111;
    public const int INIT_METHOD_CALL               = 112;
    public const int INIT_STATIC_METHOD_CALL        = 113;
    public const int ISSET_ISEMPTY_VAR              = 114;
    public const int ISSET_ISEMPTY_DIM_OBJ          = 115;
    public const int SEND_VAL_EX                    = 116;
    public const int SEND_VAR                       = 117;
    public const int INIT_USER_CALL                 = 118;
    public const int SEND_ARRAY                     = 119;
    public const int SEND_USER                      = 120;
    public const int STRLEN                         = 121;
    public const int DEFINED                        = 122;
    public const int TYPE_CHECK                     = 123;
    public const int VERIFY_RETURN_TYPE             = 124;
    public const int FE_RESET_RW                    = 125;
    public const int FE_FETCH_RW                    = 126;
    public const int FE_FREE                        = 127;
    public const int INIT_DYNAMIC_CALL              = 128;
    public const int DO_ICALL                       = 129;
    public const int DO_UCALL                       = 130;
    public const int DO_FCALL_BY_NAME               = 131;
    public const int PRE_INC_OBJ                    = 132;
    public const int PRE_DEC_OBJ                    = 133;
    public const int POST_INC_OBJ                   = 134;
    public const int POST_DEC_OBJ                   = 135;
    public const int ECHO                           = 136;
    public const int OP_DATA                        = 137;
    public const int INSTANCEOF                     = 138;
    public const int GENERATOR_CREATE               = 139;
    public const int MAKE_REF                       = 140;
    public const int DECLARE_FUNCTION               = 141;
    public const int DECLARE_LAMBDA_FUNCTION        = 142;
    public const int DECLARE_CONST                  = 143;
    public const int DECLARE_CLASS                  = 144;
    public const int DECLARE_CLASS_DELAYED          = 145;
    public const int DECLARE_ANON_CLASS             = 146;
    public const int ADD_ARRAY_UNPACK               = 147;
    public const int ISSET_ISEMPTY_PROP_OBJ         = 148;
    public const int HANDLE_EXCEPTION               = 149;
    public const int USER_OPCODE                    = 150;
    public const int ASSERT_CHECK                   = 151;
    public const int JMP_SET                        = 152;
    public const int UNSET_CV                       = 153;
    public const int ISSET_ISEMPTY_CV               = 154;
    public const int FETCH_LIST_W                   = 155;
    public const int SEPARATE                       = 156;
    public const int FETCH_CLASS_NAME               = 157;
    public const int CALL_TRAMPOLINE                = 158;
    public const int DISCARD_EXCEPTION              = 159;
    public const int YIELD                          = 160;
    public const int GENERATOR_RETURN               = 161;
    public const int FAST_CALL                      = 162;
    public const int FAST_RET                       = 163;
    public const int RECV_VARIADIC                  = 164;
    public const int SEND_UNPACK                    = 165;
    public const int YIELD_FROM                     = 166;
    public const int COPY_TMP                       = 167;
    public const int BIND_GLOBAL                    = 168;
    public const int COALESCE                       = 169;
    public const int SPACESHIP                      = 170;
    public const int FUNC_NUM_ARGS                  = 171;
    public const int FUNC_GET_ARGS                  = 172;
    public const int FETCH_STATIC_PROP_R            = 173;
    public const int FETCH_STATIC_PROP_W            = 174;
    public const int FETCH_STATIC_PROP_RW           = 175;
    public const int FETCH_STATIC_PROP_IS           = 176;
    public const int FETCH_STATIC_PROP_FUNC_ARG     = 177;
    public const int FETCH_STATIC_PROP_UNSET        = 178;
    public const int UNSET_STATIC_PROP              = 179;
    public const int ISSET_ISEMPTY_STATIC_PROP      = 180;
    public const int FETCH_CLASS_CONSTANT           = 181;
    public const int BIND_LEXICAL                   = 182;
    public const int BIND_STATIC                    = 183;
    public const int FETCH_THIS                     = 184;
    public const int SEND_FUNC_ARG                  = 185;
    public const int ISSET_ISEMPTY_THIS             = 186;
    public const int SWITCH_LONG                    = 187;
    public const int SWITCH_STRING                  = 188;
    public const int IN_ARRAY                       = 189;
    public const int COUNT                          = 190;
    public const int GET_CLASS                      = 191;
    public const int GET_CALLED_CLASS               = 192;
    public const int GET_TYPE                       = 193;
    public const int ARRAY_KEY_EXISTS               = 194;
    public const int MATCH                          = 195;
    public const int CASE_STRICT                    = 196;
    public const int MATCH_ERROR                    = 197;
    public const int JMP_NULL                       = 198;
    public const int CHECK_UNDEF_ARGS               = 199;
    public const int FETCH_GLOBALS                  = 200;
    public const int VERIFY_NEVER_TYPE              = 201;
    public const int CALLABLE_CONVERT               = 202;
    public const int BIND_INIT_STATIC_OR_JMP        = 203;
    public const int FRAMELESS_ICALL_0              = 204;
    public const int FRAMELESS_ICALL_1              = 205;
    public const int FRAMELESS_ICALL_2              = 206;
    public const int FRAMELESS_ICALL_3              = 207;
    public const int JMP_FRAMELESS                  = 208;
    public const int INIT_PARENT_PROPERTY_HOOK_CALL = 209;

    /**
     * Returns the type name of opcode
     *
     * @param int $opCode Integer value of opType
     */
    public static function name(int $opCode): string
    {
        $opCodeNames = ConstantNames::of(self::class);
        if (!isset($opCodeNames[$opCode])) {
            throw new \UnexpectedValueException('Unknown opcode ' . $opCode . '. New version of PHP?');
        }

        return $opCodeNames[$opCode];
    }

    /**
     * Installs a user opcode handler that will be used to handle specific opcode
     *
     * The handler participates in the engine hook lifecycle: it chains to a previously
     * installed user handler on ZEND_USER_OPCODE_DISPATCH, unwinds automatically at
     * Core::shutdown() and can be uninstalled explicitly via the returned hook.
     *
     * @param int     $opCode  Operation code to hook
     * @param Closure $handler Callback that will receive a control for overloaded operation code
     */
    public static function setHandler(int $opCode, Closure $handler): OpCodeHook
    {
        $hook = new OpCodeHook($opCode, $handler);
        $hook->install();

        return $hook;
    }

    /**
     * Restores the previous opcode handler by uninstalling the top hook for that opcode
     *
     * No-op when no user opcode handler is installed (keeps the historic idempotent
     * contract of this method).
     *
     * @param int $opCode Operation code
     */
    public static function restoreHandler(int $opCode): void
    {
        $topHook = Core::topHook(OpCodeHook::fieldKeyFor($opCode));
        if ($topHook === null) {
            return;
        }
        $topHook->uninstall();
    }
}
