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
use ZEngine\Constants\Defines;
use ZEngine\Core;

/**
 * Hold all internal opcode constants and provide an API to hook any existing opcode
 */
final class OpCode
{
    public const NOP = Defines::ZEND_NOP;

    public const ADD                        = Defines::ZEND_ADD;
    public const SUB                        = Defines::ZEND_SUB;
    public const MUL                        = Defines::ZEND_MUL;
    public const DIV                        = Defines::ZEND_DIV;
    public const MOD                        = Defines::ZEND_MOD;
    public const SL                         = Defines::ZEND_SL;
    public const SR                         = Defines::ZEND_SR;
    public const CONCAT                     = Defines::ZEND_CONCAT;
    public const BW_OR                      = Defines::ZEND_BW_OR;
    public const BW_AND                     = Defines::ZEND_BW_AND;
    public const BW_XOR                     = Defines::ZEND_BW_XOR;
    public const POW                        = Defines::ZEND_POW;
    public const BW_NOT                     = Defines::ZEND_BW_NOT;
    public const BOOL_NOT                   = Defines::ZEND_BOOL_NOT;
    public const BOOL_XOR                   = Defines::ZEND_BOOL_XOR;
    public const IS_IDENTICAL               = Defines::ZEND_IS_IDENTICAL;
    public const IS_NOT_IDENTICAL           = Defines::ZEND_IS_NOT_IDENTICAL;
    public const IS_EQUAL                   = Defines::ZEND_IS_EQUAL;
    public const IS_NOT_EQUAL               = Defines::ZEND_IS_NOT_EQUAL;
    public const IS_SMALLER                 = Defines::ZEND_IS_SMALLER;
    public const IS_SMALLER_OR_EQUAL        = Defines::ZEND_IS_SMALLER_OR_EQUAL;
    public const ASSIGN                     = Defines::ZEND_ASSIGN;
    public const ASSIGN_DIM                 = Defines::ZEND_ASSIGN_DIM;
    public const ASSIGN_OBJ                 = Defines::ZEND_ASSIGN_OBJ;
    public const ASSIGN_STATIC_PROP         = Defines::ZEND_ASSIGN_STATIC_PROP;
    public const ASSIGN_OP                  = Defines::ZEND_ASSIGN_OP;
    public const ASSIGN_DIM_OP              = Defines::ZEND_ASSIGN_DIM_OP;
    public const ASSIGN_OBJ_OP              = Defines::ZEND_ASSIGN_OBJ_OP;
    public const ASSIGN_STATIC_PROP_OP      = Defines::ZEND_ASSIGN_STATIC_PROP_OP;
    public const ASSIGN_REF                 = Defines::ZEND_ASSIGN_REF;
    public const QM_ASSIGN                  = Defines::ZEND_QM_ASSIGN;
    public const ASSIGN_OBJ_REF             = Defines::ZEND_ASSIGN_OBJ_REF;
    public const ASSIGN_STATIC_PROP_REF     = Defines::ZEND_ASSIGN_STATIC_PROP_REF;
    public const PRE_INC                    = Defines::ZEND_PRE_INC;
    public const PRE_DEC                    = Defines::ZEND_PRE_DEC;
    public const POST_INC                   = Defines::ZEND_POST_INC;
    public const POST_DEC                   = Defines::ZEND_POST_DEC;
    public const PRE_INC_STATIC_PROP        = Defines::ZEND_PRE_INC_STATIC_PROP;
    public const PRE_DEC_STATIC_PROP        = Defines::ZEND_PRE_DEC_STATIC_PROP;
    public const POST_INC_STATIC_PROP       = Defines::ZEND_POST_INC_STATIC_PROP;
    public const POST_DEC_STATIC_PROP       = Defines::ZEND_POST_DEC_STATIC_PROP;
    public const JMP                        = Defines::ZEND_JMP;
    public const JMPZ                       = Defines::ZEND_JMPZ;
    public const JMPNZ                      = Defines::ZEND_JMPNZ;
    public const JMPZNZ                     = Defines::ZEND_JMPZNZ;
    public const JMPZ_EX                    = Defines::ZEND_JMPZ_EX;
    public const JMPNZ_EX                   = Defines::ZEND_JMPNZ_EX;
    public const CASE                       = Defines::ZEND_CASE;
    public const CHECK_VAR                  = Defines::ZEND_CHECK_VAR;
    public const SEND_VAR_NO_REF_EX         = Defines::ZEND_SEND_VAR_NO_REF_EX;
    public const CAST                       = Defines::ZEND_CAST;
    public const BOOL                       = Defines::ZEND_BOOL;
    public const FAST_CONCAT                = Defines::ZEND_FAST_CONCAT;
    public const ROPE_INIT                  = Defines::ZEND_ROPE_INIT;
    public const ROPE_ADD                   = Defines::ZEND_ROPE_ADD;
    public const ROPE_END                   = Defines::ZEND_ROPE_END;
    public const BEGIN_SILENCE              = Defines::ZEND_BEGIN_SILENCE;
    public const END_SILENCE                = Defines::ZEND_END_SILENCE;
    public const INIT_FCALL_BY_NAME         = Defines::ZEND_INIT_FCALL_BY_NAME;
    public const DO_FCALL                   = Defines::ZEND_DO_FCALL;
    public const INIT_FCALL                 = Defines::ZEND_INIT_FCALL;
    public const RETURN                     = Defines::ZEND_RETURN;
    public const RECV                       = Defines::ZEND_RECV;
    public const RECV_INIT                  = Defines::ZEND_RECV_INIT;
    public const SEND_VAL                   = Defines::ZEND_SEND_VAL;
    public const SEND_VAR_EX                = Defines::ZEND_SEND_VAR_EX;
    public const SEND_REF                   = Defines::ZEND_SEND_REF;
    public const NEW                        = Defines::ZEND_NEW;
    public const INIT_NS_FCALL_BY_NAME      = Defines::ZEND_INIT_NS_FCALL_BY_NAME;
    public const FREE                       = Defines::ZEND_FREE;
    public const INIT_ARRAY                 = Defines::ZEND_INIT_ARRAY;
    public const ADD_ARRAY_ELEMENT          = Defines::ZEND_ADD_ARRAY_ELEMENT;
    public const INCLUDE_OR_EVAL            = Defines::ZEND_INCLUDE_OR_EVAL;
    public const UNSET_VAR                  = Defines::ZEND_UNSET_VAR;
    public const UNSET_DIM                  = Defines::ZEND_UNSET_DIM;
    public const UNSET_OBJ                  = Defines::ZEND_UNSET_OBJ;
    public const FE_RESET_R                 = Defines::ZEND_FE_RESET_R;
    public const FE_FETCH_R                 = Defines::ZEND_FE_FETCH_R;
    public const EXIT                       = Defines::ZEND_EXIT;
    public const FETCH_R                    = Defines::ZEND_FETCH_R;
    public const FETCH_DIM_R                = Defines::ZEND_FETCH_DIM_R;
    public const FETCH_OBJ_R                = Defines::ZEND_FETCH_OBJ_R;
    public const FETCH_W                    = Defines::ZEND_FETCH_W;
    public const FETCH_DIM_W                = Defines::ZEND_FETCH_DIM_W;
    public const FETCH_OBJ_W                = Defines::ZEND_FETCH_OBJ_W;
    public const FETCH_RW                   = Defines::ZEND_FETCH_RW;
    public const FETCH_DIM_RW               = Defines::ZEND_FETCH_DIM_RW;
    public const FETCH_OBJ_RW               = Defines::ZEND_FETCH_OBJ_RW;
    public const FETCH_IS                   = Defines::ZEND_FETCH_IS;
    public const FETCH_DIM_IS               = Defines::ZEND_FETCH_DIM_IS;
    public const FETCH_OBJ_IS               = Defines::ZEND_FETCH_OBJ_IS;
    public const FETCH_FUNC_ARG             = Defines::ZEND_FETCH_FUNC_ARG;
    public const FETCH_DIM_FUNC_ARG         = Defines::ZEND_FETCH_DIM_FUNC_ARG;
    public const FETCH_OBJ_FUNC_ARG         = Defines::ZEND_FETCH_OBJ_FUNC_ARG;
    public const FETCH_UNSET                = Defines::ZEND_FETCH_UNSET;
    public const FETCH_DIM_UNSET            = Defines::ZEND_FETCH_DIM_UNSET;
    public const FETCH_OBJ_UNSET            = Defines::ZEND_FETCH_OBJ_UNSET;
    public const FETCH_LIST_R               = Defines::ZEND_FETCH_LIST_R;
    public const FETCH_CONSTANT             = Defines::ZEND_FETCH_CONSTANT;
    public const CHECK_FUNC_ARG             = Defines::ZEND_CHECK_FUNC_ARG;
    public const EXT_STMT                   = Defines::ZEND_EXT_STMT;
    public const EXT_FCALL_BEGIN            = Defines::ZEND_EXT_FCALL_BEGIN;
    public const EXT_FCALL_END              = Defines::ZEND_EXT_FCALL_END;
    public const EXT_NOP                    = Defines::ZEND_EXT_NOP;
    public const TICKS                      = Defines::ZEND_TICKS;
    public const SEND_VAR_NO_REF            = Defines::ZEND_SEND_VAR_NO_REF;
    public const CATCH                      = Defines::ZEND_CATCH;
    public const THROW                      = Defines::ZEND_THROW;
    public const FETCH_CLASS                = Defines::ZEND_FETCH_CLASS;
    public const CLONE                      = Defines::ZEND_CLONE;
    public const RETURN_BY_REF              = Defines::ZEND_RETURN_BY_REF;
    public const INIT_METHOD_CALL           = Defines::ZEND_INIT_METHOD_CALL;
    public const INIT_STATIC_METHOD_CALL    = Defines::ZEND_INIT_STATIC_METHOD_CALL;
    public const ISSET_ISEMPTY_VAR          = Defines::ZEND_ISSET_ISEMPTY_VAR;
    public const ISSET_ISEMPTY_DIM_OBJ      = Defines::ZEND_ISSET_ISEMPTY_DIM_OBJ;
    public const SEND_VAL_EX                = Defines::ZEND_SEND_VAL_EX;
    public const SEND_VAR                   = Defines::ZEND_SEND_VAR;
    public const INIT_USER_CALL             = Defines::ZEND_INIT_USER_CALL;
    public const SEND_ARRAY                 = Defines::ZEND_SEND_ARRAY;
    public const SEND_USER                  = Defines::ZEND_SEND_USER;
    public const STRLEN                     = Defines::ZEND_STRLEN;
    public const DEFINED                    = Defines::ZEND_DEFINED;
    public const TYPE_CHECK                 = Defines::ZEND_TYPE_CHECK;
    public const VERIFY_RETURN_TYPE         = Defines::ZEND_VERIFY_RETURN_TYPE;
    public const FE_RESET_RW                = Defines::ZEND_FE_RESET_RW;
    public const FE_FETCH_RW                = Defines::ZEND_FE_FETCH_RW;
    public const FE_FREE                    = Defines::ZEND_FE_FREE;
    public const INIT_DYNAMIC_CALL          = Defines::ZEND_INIT_DYNAMIC_CALL;
    public const DO_ICALL                   = Defines::ZEND_DO_ICALL;
    public const DO_UCALL                   = Defines::ZEND_DO_UCALL;
    public const DO_FCALL_BY_NAME           = Defines::ZEND_DO_FCALL_BY_NAME;
    public const PRE_INC_OBJ                = Defines::ZEND_PRE_INC_OBJ;
    public const PRE_DEC_OBJ                = Defines::ZEND_PRE_DEC_OBJ;
    public const POST_INC_OBJ               = Defines::ZEND_POST_INC_OBJ;
    public const POST_DEC_OBJ               = Defines::ZEND_POST_DEC_OBJ;
    public const ECHO                       = Defines::ZEND_ECHO;
    public const OP_DATA                    = Defines::ZEND_OP_DATA;
    public const INSTANCEOF                 = Defines::ZEND_INSTANCEOF;
    public const GENERATOR_CREATE           = Defines::ZEND_GENERATOR_CREATE;
    public const MAKE_REF                   = Defines::ZEND_MAKE_REF;
    public const DECLARE_FUNCTION           = Defines::ZEND_DECLARE_FUNCTION;
    public const DECLARE_LAMBDA_FUNCTION    = Defines::ZEND_DECLARE_LAMBDA_FUNCTION;
    public const DECLARE_CONST              = Defines::ZEND_DECLARE_CONST;
    public const DECLARE_CLASS              = Defines::ZEND_DECLARE_CLASS;
    public const DECLARE_CLASS_DELAYED      = Defines::ZEND_DECLARE_CLASS_DELAYED;
    public const DECLARE_ANON_CLASS         = Defines::ZEND_DECLARE_ANON_CLASS;
    public const ADD_ARRAY_UNPACK           = Defines::ZEND_ADD_ARRAY_UNPACK;
    public const ISSET_ISEMPTY_PROP_OBJ     = Defines::ZEND_ISSET_ISEMPTY_PROP_OBJ;
    public const HANDLE_EXCEPTION           = Defines::ZEND_HANDLE_EXCEPTION;
    public const USER_OPCODE                = Defines::ZEND_USER_OPCODE;
    public const ASSERT_CHECK               = Defines::ZEND_ASSERT_CHECK;
    public const JMP_SET                    = Defines::ZEND_JMP_SET;
    public const UNSET_CV                   = Defines::ZEND_UNSET_CV;
    public const ISSET_ISEMPTY_CV           = Defines::ZEND_ISSET_ISEMPTY_CV;
    public const FETCH_LIST_W               = Defines::ZEND_FETCH_LIST_W;
    public const SEPARATE                   = Defines::ZEND_SEPARATE;
    public const FETCH_CLASS_NAME           = Defines::ZEND_FETCH_CLASS_NAME;
    public const CALL_TRAMPOLINE            = Defines::ZEND_CALL_TRAMPOLINE;
    public const DISCARD_EXCEPTION          = Defines::ZEND_DISCARD_EXCEPTION;
    public const YIELD                      = Defines::ZEND_YIELD;
    public const GENERATOR_RETURN           = Defines::ZEND_GENERATOR_RETURN;
    public const FAST_CALL                  = Defines::ZEND_FAST_CALL;
    public const FAST_RET                   = Defines::ZEND_FAST_RET;
    public const RECV_VARIADIC              = Defines::ZEND_RECV_VARIADIC;
    public const SEND_UNPACK                = Defines::ZEND_SEND_UNPACK;
    public const YIELD_FROM                 = Defines::ZEND_YIELD_FROM;
    public const COPY_TMP                   = Defines::ZEND_COPY_TMP;
    public const BIND_GLOBAL                = Defines::ZEND_BIND_GLOBAL;
    public const COALESCE                   = Defines::ZEND_COALESCE;
    public const SPACESHIP                  = Defines::ZEND_SPACESHIP;
    public const FUNC_NUM_ARGS              = Defines::ZEND_FUNC_NUM_ARGS;
    public const FUNC_GET_ARGS              = Defines::ZEND_FUNC_GET_ARGS;
    public const FETCH_STATIC_PROP_R        = Defines::ZEND_FETCH_STATIC_PROP_R;
    public const FETCH_STATIC_PROP_W        = Defines::ZEND_FETCH_STATIC_PROP_W;
    public const FETCH_STATIC_PROP_RW       = Defines::ZEND_FETCH_STATIC_PROP_RW;
    public const FETCH_STATIC_PROP_IS       = Defines::ZEND_FETCH_STATIC_PROP_IS;
    public const FETCH_STATIC_PROP_FUNC_ARG = Defines::ZEND_FETCH_STATIC_PROP_FUNC_ARG;
    public const FETCH_STATIC_PROP_UNSET    = Defines::ZEND_FETCH_STATIC_PROP_UNSET;
    public const UNSET_STATIC_PROP          = Defines::ZEND_UNSET_STATIC_PROP;
    public const ISSET_ISEMPTY_STATIC_PROP  = Defines::ZEND_ISSET_ISEMPTY_STATIC_PROP;
    public const FETCH_CLASS_CONSTANT       = Defines::ZEND_FETCH_CLASS_CONSTANT;
    public const BIND_LEXICAL               = Defines::ZEND_BIND_LEXICAL;
    public const BIND_STATIC                = Defines::ZEND_BIND_STATIC;
    public const FETCH_THIS                 = Defines::ZEND_FETCH_THIS;
    public const SEND_FUNC_ARG              = Defines::ZEND_SEND_FUNC_ARG;
    public const ISSET_ISEMPTY_THIS         = Defines::ZEND_ISSET_ISEMPTY_THIS;
    public const SWITCH_LONG                = Defines::ZEND_SWITCH_LONG;
    public const SWITCH_STRING              = Defines::ZEND_SWITCH_STRING;
    public const IN_ARRAY                   = Defines::ZEND_IN_ARRAY;
    public const COUNT                      = Defines::ZEND_COUNT;
    public const GET_CLASS                  = Defines::ZEND_GET_CLASS;
    public const GET_CALLED_CLASS           = Defines::ZEND_GET_CALLED_CLASS;
    public const GET_TYPE                   = Defines::ZEND_GET_TYPE;
    public const ARRAY_KEY_EXIST            = Defines::ZEND_ARRAY_KEY_EXISTS;
    public const MATCH                      = Defines::ZEND_MATCH;
    public const CASE_STRICT                = Defines::ZEND_CASE_STRICT;
    public const MATCH_ERROR                = Defines::ZEND_MATCH_ERROR;
    public const JMP_NULL                   = Defines::ZEND_JMP_NULL;
    public const CHECK_UNDEF_ARGS           = Defines::ZEND_CHECK_UNDEF_ARGS;

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
