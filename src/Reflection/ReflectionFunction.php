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

namespace ZEngine\Reflection;

use FFI\CData;
use ReflectionFunction as NativeReflectionFunction;
use ZEngine\Core;
use ZEngine\Type\StringEntry;

class ReflectionFunction extends NativeReflectionFunction
{
    use FunctionLikeTrait;

    public function __construct(string $functionName)
    {
        parent::__construct($functionName);

        $normalizedName     = strtolower($functionName);
        $functionEntryValue = Core::$executor->functionTable->find($normalizedName);
        if ($functionEntryValue === null) {
            throw new \ReflectionException("Function {$functionName} should be in the engine.");
        }
        $this->pointer = $functionEntryValue->getRawFunction();
    }

    /**
     * Creates a reflection from the zend_function structure
     *
     * @param CData $functionEntry Pointer to the structure
     *
     * @return ReflectionFunction
     */
    public static function fromCData(CData $functionEntry): ReflectionFunction
    {
        /** @var ReflectionFunction $reflectionFunction */
        $reflectionFunction = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        if ($functionEntry->type === Core::ZEND_INTERNAL_FUNCTION) {
            $functionNamePtr = $functionEntry->function_name;
        } else {
            $functionNamePtr = $functionEntry->common->function_name;
        }
        if ($functionNamePtr !== null) {
            $functionName = StringEntry::fromCData($functionNamePtr);
            call_user_func(
                [$reflectionFunction, 'parent::__construct'],
                $functionName->getStringValue()
            );
        }
        $reflectionFunction->pointer = $functionEntry;

        return $reflectionFunction;
    }

    /**
     * Returns a user-friendly representation of internal structure to prevent segfault
     */
    public function __debugInfo(): array
    {
        return [
            'name' => $this->getName(),
        ];
    }

    /**
     * Returns the hash key for function or method
     */
    protected function getHash(): string
    {
        return $this->name;
    }
}
