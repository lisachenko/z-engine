<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\PHPStan;

use FFI\CData;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use ZEngine\Core;

/**
 * Resolves the return type of the typed Core entry points (new(), trackedNew(),
 * cast(), pointerAtAddress()) from their literal $type argument:
 *
 *  - a generated struct stub class-string (ZEngine\Generated\zval::class) types the
 *    returned handle as that stub class, carrying the struct's fields statically;
 *  - every other string (legacy C type names like "zval", "zend_ast **", "char[16]")
 *    stays FFI\CData exactly as before.
 *
 * The conditional-return docblocks on Core cannot make this distinction on their
 * own: PHPStan binds `$type is class-string<T>` for ANY constant string, so a
 * literal like 'zend_ast **' would resolve to an unknown class instead of CData.
 * This extension is the authoritative resolution for PHPStan; PhpStorm gets the
 * same mapping from the generated .phpstorm.meta.php.
 */
final class TypedEntryPointReturnExtension implements DynamicStaticMethodReturnTypeExtension
{
    private const STUB_NAMESPACE = 'ZEngine\\Generated\\';

    private const SUPPORTED_METHODS = ['new', 'trackedNew', 'cast', 'pointerAtAddress'];

    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    public function getClass(): string
    {
        return Core::class;
    }

    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return in_array($methodReflection->getName(), self::SUPPORTED_METHODS, true);
    }

    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        $arguments = $methodCall->getArgs();
        if ($arguments === []) {
            return null;
        }
        $typeArgument    = $scope->getType($arguments[0]->value);
        $constantStrings = $typeArgument->getConstantStrings();
        if (count($constantStrings) === 1) {
            $typeName = $constantStrings[0]->getValue();
            if (str_starts_with($typeName, self::STUB_NAMESPACE) && $this->reflectionProvider->hasClass($typeName)) {
                return new ObjectType($typeName);
            }
        }

        // Legacy C type name literals and dynamic strings: the handle is plain CData
        return new ObjectType(CData::class);
    }
}
