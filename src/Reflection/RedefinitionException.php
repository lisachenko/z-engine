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

namespace ZEngine\Reflection;

/**
 * Raised when redefine() or addMethod() cannot accept the donor body, the alias name or the
 * target entry. Every factory below is checked BEFORE engine memory is touched, so a caught
 * instance always means "nothing was modified".
 *
 * Every failure mode has a named static constructor (project convention, see AGENTS.md).
 */
class RedefinitionException extends \ReflectionException
{
    public static function signatureMismatch(string $entrySignature, string $donorSignature): self
    {
        return new self(
            'Given function signature: "' . $donorSignature . '"'
            . ' should be compatible with original "' . $entrySignature . '"',
        );
    }

    public static function internalEntryRequiresClosure(string $entryName): self
    {
        return new self(
            "Cannot redefine the internal function {$entryName}() with a method donor: an internal "
            . 'entry has no op_array to adopt, only its handler can be replaced - pass a closure',
        );
    }

    public static function aliasUnsupportedForInternalEntry(string $entryName): self
    {
        return new self(
            "Cannot preserve the body of the internal function {$entryName}() under an alias: "
            . 'an internal entry has no op_array to publish',
        );
    }

    public static function donorIsInternal(string $donorName): self
    {
        return new self("Cannot adopt the body of {$donorName}(): it is an internal function without an op_array");
    }

    public static function donorIsAbstract(string $donorName): self
    {
        return new self("Cannot adopt the body of {$donorName}(): an abstract method has no body");
    }

    public static function donorIsEntry(string $entryName): self
    {
        return new self("Cannot redefine {$entryName}() with its own body");
    }

    public static function donorNotIntrospectable(string $donorName, \Throwable $reason): self
    {
        return new self(
            "Cannot read the signature of the donor {$donorName}(): obtain the donor through "
            . "ReflectionClass::getMethod() or `new ReflectionMethod()` ({$reason->getMessage()})",
            0,
            $reason,
        );
    }

    public static function staticModifierMismatch(string $entryName, string $donorName): self
    {
        return new self(
            "Cannot use {$donorName}() as the body of {$entryName}(): one is static and the other "
            . 'is not, so the receiver of the call would not match the body',
        );
    }

    public static function invalidName(string $name): self
    {
        return new self("\"{$name}\" is not a valid function or method name");
    }

    public static function aliasAlreadyDeclared(string $className, string $alias): self
    {
        return new self(
            "Cannot preserve the previous body as {$className}::{$alias}(): the class already "
            . 'declares a method of that name (a body preserved by an earlier redefine()? restore '
            . 'or remove it first)',
        );
    }

    public static function aliasCollidesWithInheritedMethod(string $className, string $alias, string $declaringClass): self
    {
        return new self(
            "Cannot preserve the previous body as {$className}::{$alias}(): the class inherits a "
            . "non-private method of that name from {$declaringClass}, which a private alias "
            . 'cannot override',
        );
    }

    public static function functionAlreadyExists(string $functionName): self
    {
        return new self(
            "Cannot preserve the previous body as {$functionName}(): a function of that name "
            . 'already exists in the engine',
        );
    }

    public static function methodAlreadyDeclared(string $className, string $methodName): self
    {
        return new self(
            "Cannot add {$className}::{$methodName}(): the class already declares that method - "
            . 'use ReflectionMethod::redefine() to replace its body',
        );
    }

    public static function finalMethodOverride(string $className, string $methodName, string $declaringClass): self
    {
        return new self(
            "Cannot add {$className}::{$methodName}(): it would override the final method "
            . "{$declaringClass}::{$methodName}()",
        );
    }

    public static function visibilityNarrowed(string $className, string $methodName, string $declaringClass): self
    {
        return new self(
            "Cannot add {$className}::{$methodName}(): its visibility is narrower than that of "
            . "the inherited {$declaringClass}::{$methodName}()",
        );
    }
}
