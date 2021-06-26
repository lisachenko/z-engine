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
use FFI\CData;
use ZEngine\Core;

/**
 * Hold all internal opcode constants and provide an API to hook any existing opcode
 */
final class OpCode
{
    public const NOP = 0;

    public const ADD                        = 1;
    public const SUB                        = 2;
    public const MUL                        = 3;
    public const DIV                        = 4;
    public const MOD                        = 5;
    public const SL                         = 6;
    public const SR                         = 7;
    public const CONCAT                     = 8;
    public const BW_OR                      = 9;
    public const BW_AND                     = 10;
    public const BW_XOR                     = 11;
    public const POW                        = 12;
    public const BW_NOT                     = 13;
    public const BOOL_NOT                   = 14;
    public const BOOL_XOR                   = 15;
    public const IS_IDENTICAL               = 16;
    public const IS_NOT_IDENTICAL           = 17;
    public const IS_EQUAL                   = 18;
    public const IS_NOT_EQUAL               = 19;
    public const IS_SMALLER                 = 20;
    public const IS_SMALLER_OR_EQUAL        = 21;
    public const ASSIGN                     = 22;
    public const ASSIGN_DIM                 = 23;
    public const ASSIGN_OBJ                 = 24;
    public const ASSIGN_STATIC_PROP         = 25;
    public const ASSIGN_OP                  = 26;
    public const ASSIGN_DIM_OP              = 27;
    public const ASSIGN_OBJ_OP              = 28;
    public const ASSIGN_STATIC_PROP_OP      = 29;
    public const ASSIGN_REF                 = 30;
    public const QM_ASSIGN                  = 31;
    public const ASSIGN_OBJ_REF             = 32;
    public const ASSIGN_STATIC_PROP_REF     = 33;
    public const PRE_INC                    = 34;
    public const PRE_DEC                    = 35;
    public const POST_INC                   = 36;
    public const POST_DEC                   = 37;
    public const PRE_INC_STATIC_PROP        = 38;
    public const PRE_DEC_STATIC_PROP        = 39;
    public const POST_INC_STATIC_PROP       = 40;
    public const POST_DEC_STATIC_PROP       = 41;
    public const JMP                        = 42;
    public const JMPZ                       = 43;
    public const JMPNZ                      = 44;
    public const JMPZNZ                     = 45;
    public const JMPZ_EX                    = 46;
    public const JMPNZ_EX                   = 47;
    public const CASE                       = 48;
    public const CHECK_VAR                  = 49;
    public const SEND_VAR_NO_REF_EX         = 50;
    public const CAST                       = 51;
    public const BOOL                       = 52;
    public const FAST_CONCAT                = 53;
    public const ROPE_INIT                  = 54;
    public const ROPE_ADD                   = 55;
    public const ROPE_END                   = 56;
    public const BEGIN_SILENCE              = 57;
    public const END_SILENCE                = 58;
    public const INIT_FCALL_BY_NAME         = 59;
    public const DO_FCALL                   = 60;
    public const INIT_FCALL                 = 61;
    public const RETURN                     = 62;
    public const RECV                       = 63;
    public const RECV_INIT                  = 64;
    public const SEND_VAL                   = 65;
    public const SEND_VAR_EX                = 66;
    public const SEND_REF                   = 67;
    public const NEW                        = 68;
    public const INIT_NS_FCALL_BY_NAME      = 69;
    public const FREE                       = 70;
    public const INIT_ARRAY                 = 71;
    public const ADD_ARRAY_ELEMENT          = 72;
    public const INCLUDE_OR_EVAL            = 73;
    public const UNSET_VAR                  = 74;
    public const UNSET_DIM                  = 75;
    public const UNSET_OBJ                  = 76;
    public const FE_RESET_R                 = 77;
    public const FE_FETCH_R                 = 78;
    public const EXIT                       = 79;
    public const FETCH_R                    = 80;
    public const FETCH_DIM_R                = 81;
    public const FETCH_OBJ_R                = 82;
    public const FETCH_W                    = 83;
    public const FETCH_DIM_W                = 84;
    public const FETCH_OBJ_W                = 85;
    public const FETCH_RW                   = 86;
    public const FETCH_DIM_RW               = 87;
    public const FETCH_OBJ_RW               = 88;
    public const FETCH_IS                   = 89;
    public const FETCH_DIM_IS               = 90;
    public const FETCH_OBJ_IS               = 91;
    public const FETCH_FUNC_ARG             = 92;
    public const FETCH_DIM_FUNC_ARG         = 93;
    public const FETCH_OBJ_FUNC_ARG         = 94;
    public const FETCH_UNSET                = 95;
    public const FETCH_DIM_UNSET            = 96;
    public const FETCH_OBJ_UNSET            = 97;
    public const FETCH_LIST_R               = 98;
    public const FETCH_CONSTANT             = 99;
    public const CHECK_FUNC_ARG             = 100;
    public const EXT_STMT                   = 101;
    public const EXT_FCALL_BEGIN            = 102;
    public const EXT_FCALL_END              = 103;
    public const EXT_NOP                    = 104;
    public const TICKS                      = 105;
    public const SEND_VAR_NO_REF            = 106;
    public const CATCH                      = 107;
    public const THROW                      = 108;
    public const FETCH_CLASS                = 109;
    public const CLONE                      = 110;
    public const RETURN_BY_REF              = 111;
    public const INIT_METHOD_CALL           = 112;
    public const INIT_STATIC_METHOD_CALL    = 113;
    public const ISSET_ISEMPTY_VAR          = 114;
    public const ISSET_ISEMPTY_DIM_OBJ      = 115;
    public const SEND_VAL_EX                = 116;
    public const SEND_VAR                   = 117;
    public const INIT_USER_CALL             = 118;
    public const SEND_ARRAY                 = 119;
    public const SEND_USER                  = 120;
    public const STRLEN                     = 121;
    public const DEFINED                    = 122;
    public const TYPE_CHECK                 = 123;
    public const VERIFY_RETURN_TYPE         = 124;
    public const FE_RESET_RW                = 125;
    public const FE_FETCH_RW                = 126;
    public const FE_FREE                    = 127;
    public const INIT_DYNAMIC_CALL          = 128;
    public const DO_ICALL                   = 129;
    public const DO_UCALL                   = 130;
    public const DO_FCALL_BY_NAME           = 131;
    public const PRE_INC_OBJ                = 132;
    public const PRE_DEC_OBJ                = 133;
    public const POST_INC_OBJ               = 134;
    public const POST_DEC_OBJ               = 135;
    public const ECHO                       = 136;
    public const OP_DATA                    = 137;
    public const INSTANCEOF                 = 138;
    public const GENERATOR_CREATE           = 139;
    public const MAKE_REF                   = 140;
    public const DECLARE_FUNCTION           = 141;
    public const DECLARE_LAMBDA_FUNCTION    = 142;
    public const DECLARE_CONST              = 143;
    public const DECLARE_CLASS              = 144;
    public const DECLARE_CLASS_DELAYED      = 145;
    public const DECLARE_ANON_CLASS         = 146;
    public const ADD_ARRAY_UNPACK           = 147;
    public const ISSET_ISEMPTY_PROP_OBJ     = 148;
    public const HANDLE_EXCEPTION           = 149;
    public const USER_OPCODE                = 150;
    public const ASSERT_CHECK               = 151;
    public const JMP_SET                    = 152;
    public const UNSET_CV                   = 153;
    public const ISSET_ISEMPTY_CV           = 154;
    public const FETCH_LIST_W               = 155;
    public const SEPARATE                   = 156;
    public const FETCH_CLASS_NAME           = 157;
    public const CALL_TRAMPOLINE            = 158;
    public const DISCARD_EXCEPTION          = 159;
    public const YIELD                      = 160;
    public const GENERATOR_RETURN           = 161;
    public const FAST_CALL                  = 162;
    public const FAST_RET                   = 163;
    public const RECV_VARIADIC              = 164;
    public const SEND_UNPACK                = 165;
    public const YIELD_FROM                 = 166;
    public const COPY_TMP                   = 167;
    public const BIND_GLOBAL                = 168;
    public const COALESCE                   = 169;
    public const SPACESHIP                  = 170;
    public const FUNC_NUM_ARGS              = 171;
    public const FUNC_GET_ARGS              = 172;
    public const FETCH_STATIC_PROP_R        = 173;
    public const FETCH_STATIC_PROP_W        = 174;
    public const FETCH_STATIC_PROP_RW       = 175;
    public const FETCH_STATIC_PROP_IS       = 176;
    public const FETCH_STATIC_PROP_FUNC_ARG = 177;
    public const FETCH_STATIC_PROP_UNSET    = 178;
    public const UNSET_STATIC_PROP          = 179;
    public const ISSET_ISEMPTY_STATIC_PROP  = 180;
    public const FETCH_CLASS_CONSTANT       = 181;
    public const BIND_LEXICAL               = 182;
    public const BIND_STATIC                = 183;
    public const FETCH_THIS                 = 184;
    public const SEND_FUNC_ARG              = 185;
    public const ISSET_ISEMPTY_THIS         = 186;
    public const SWITCH_LONG                = 187;
    public const SWITCH_STRING              = 188;
    public const IN_ARRAY                   = 189;
    public const COUNT                      = 190;
    public const GET_CLASS                  = 191;
    public const GET_CALLED_CLASS           = 192;
    public const GET_TYPE                   = 193;
    public const ARRAY_KEY_EXIST            = 194;
    public const MATCH                      = 195;
    public const CASE_STRICT                = 196;
    public const MATCH_ERROR                = 197;
    public const JMP_NULL                   = 198;
    public const CHECK_UNDEF_ARGS           = 199;

    /**
     * Reversed class constants, containing names by number
     *
     * @var string[]
     */
    private static array $opCodeNames = [];

    /**
     * Returns the type name of opcode
     *
     * @param int $opCode Integer value of opType
     */
    public static function name(int $opCode): string
    {
        if (empty(self::$opCodeNames)) {
            self::$opCodeNames = array_flip((new \ReflectionClass(self::class))->getConstants());
        }

        if (!isset(self::$opCodeNames[$opCode])) {
            throw new \UnexpectedValueException('Unknown opcode ' . $opCode . '. New version of PHP?');
        }

        return self::$opCodeNames[$opCode];
    }

    /**
     * Installs a user opcode handler that will be used to handle specific opcode
     *
     * @param int     $opCode  Operation code to hook
     * @param Closure $handler Callback that will receive a control for overloaded operation code
     */
    public static function setHandler(int $opCode, Closure $handler): void
    {
        self::ensureValidOpCodeHandler($handler);
        $result = Core::call('zend_set_user_opcode_handler', $opCode, function (CData $state) use ($handler) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $class = $trace[1]['class'] ?? '';
            if (strpos($class, 'ZEngine') === 0) {
                // For all our internal classes just proceed with default opcode handler

                return Core::ZEND_USER_OPCODE_DISPATCH;
            }
            $executionState = new ExecutionData($state);
            $handleResult   = $handler($executionState);

            return $handleResult;
        });
        if ($result === Core::FAILURE) {
            throw new \RuntimeException('Can not install user opcode handler');
        }
    }

    /**
     * Restores default opcode handler
     *
     * @param int $opCode Operation code
     */
    public static function restoreHandler(int $opCode): void
    {
        $result = Core::call('zend_set_user_opcode_handler', $opCode, null);
        if ($result === Core::FAILURE) {
            throw new \RuntimeException('Can not restore original opcode handler');
        }
    }

    /**
     * Ensures that given callback can be used as opcode handler, otherwise throws an error
     *
     * @param Closure $handler User-defined opcode handler
     */
    private static function ensureValidOpCodeHandler(Closure $handler): void
    {
        $reflection = new \ReflectionFunction($handler);

        $hasOneArgument     = $reflection->getNumberOfParameters() === 1;
        $hasValidReturnType = $reflection->hasReturnType() && ($reflection->getReturnType()->getName() === 'int');
        if (!$hasValidReturnType || !$hasOneArgument) {
            throw new \InvalidArgumentException('Opcode handler signature should be: function($scope): int {}');
        }
    }
}
