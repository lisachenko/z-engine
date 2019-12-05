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

namespace ZEngine\Stub;

use ZEngine\ClassExtension\ObjectCastInterface;
use ZEngine\ClassExtension\ObjectCompareValuesInterface;
use ZEngine\ClassExtension\ObjectCreateInterface;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\ClassExtension\ObjectDoOperationInterface;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\System\OpCode;

class NativeNumber implements
    ObjectCreateInterface,
    ObjectCompareValuesInterface,
    ObjectDoOperationInterface,
    ObjectCastInterface
{
    use ObjectCreateTrait;

    private $value;

    public function __construct($value)
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('Only numeric values are allowed');
        }
        $this->value = $value;
    }

    /**
     * @param NativeNumber $instance
     * @inheritDoc
     */
    public static function __cast(object $instance, int $typeTo)
    {
        switch ($typeTo) {
            case ReflectionValue::_IS_NUMBER:
            case ReflectionValue::IS_LONG:
                return (int) $instance->value;
            case ReflectionValue::IS_DOUBLE:
                return (float) $instance->value;
        }

        throw new \UnexpectedValueException('Can not cast number to the ' . ReflectionValue::name($typeTo));
    }

    /**
     * @inheritDoc
     */
    public static function __compare($one, $another): int
    {
        $left  = self::getNumericValue($one);
        $right = self::getNumericValue($another);

        return $left <=> $right;
    }

    /**
     * @inheritDoc
     */
    public static function __doOperation(int $opCode, $left, $right)
    {
        $left  = self::getNumericValue($left);
        $right = self::getNumericValue($right);
        switch ($opCode) {
            case OpCode::ADD:
                $result = $left + $right;
                break;
            case OpCode::SUB:
                $result = $left - $right;
                break;
            case OpCode::MUL:
                $result = $left * $right;
                break;
            case OpCode::DIV:
                $result = $left / $right;
                break;
            default:
                throw new \UnexpectedValueException("Opcode " . OpCode::name($opCode) . " wasn't held.");
        }

        return new static($result);
    }

    /**
     * @param $one
     *
     * @return int|string
     */
    private static function getNumericValue($one)
    {
        if ($one instanceof NativeNumber) {
            $left = $one->value;
        } elseif (is_numeric($one)) {
            $left = $one;
        } else {
            throw new \UnexpectedValueException('NativeNumber can be compared only with numeric values and itself');
        }

        return $left;
}
}
