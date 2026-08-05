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

namespace ZEngine\HotSwap;

/**
 * Raised when a class delta cannot be prepared (unparsable or incompatible source,
 * unsupported shape change - see the support matrix in docs/hot-swap.md) or when
 * an apply step fails and the delta was rolled back.
 *
 * Every failure mode has a named static constructor (project convention, see AGENTS.md).
 */
class HotSwapException extends \ReflectionException
{
    public static function classNotLoaded(string $className): self
    {
        return new self("Class {$className} is not loaded, nothing to hot-swap");
    }

    public static function internalClass(string $className): self
    {
        return new self("Cannot hot-swap internal class {$className}");
    }

    public static function notLinked(string $className): self
    {
        return new self("Cannot hot-swap class {$className}: it is not linked yet");
    }

    public static function unsupportedKind(string $className): self
    {
        return new self("Cannot hot-swap {$className}: interfaces, traits and enums are not supported");
    }

    public static function sourceDoesNotParse(string $className, \Throwable $cause): self
    {
        return new self("Hot-swap source for {$className} does not parse: {$cause->getMessage()}", 0, $cause);
    }

    public static function sourceMissingClass(string $shortName, string $found): self
    {
        return new self("Hot-swap source must declare class {$shortName}, found: {$found}");
    }

    public static function sourceDidNotCompile(string $className, \Throwable $cause): self
    {
        return new self("Hot-swap source for {$className} failed to compile: {$cause->getMessage()}", 0, $cause);
    }

    public static function classNotDeclared(string $className): self
    {
        return new self(
            "Hot-swap source for {$className} compiled, but did not declare the class "
            . 'under its fully-qualified name (check the namespace statement)',
        );
    }

    public static function parentChanged(string $className): self
    {
        return new self(
            "Hot-swap source for {$className} changes the parent class - hierarchy changes are not supported",
        );
    }

    public static function interfacesChanged(string $className): self
    {
        return new self(
            "Hot-swap source for {$className} changes the implemented interfaces - "
            . 'hierarchy changes are not supported',
        );
    }

    public static function propertySurfaceChanged(string $className): self
    {
        return new self(
            "Hot-swap source for {$className} changes the property surface "
            . '(added/removed/reordered/retyped properties) - only default value changes are supported',
        );
    }

    public static function inheritedMethodOverride(string $className, string $methodName): self
    {
        return new self(
            "Hot-swap source for {$className} overrides the inherited method {$methodName}() - "
            . 'adding an override of an inherited method is not supported',
        );
    }

    public static function magicMethodAdded(string $className, string $methodName): self
    {
        return new self(
            "Hot-swap source for {$className} adds the magic method {$methodName}() - "
            . 'adding or removing magic methods and constructors is not supported',
        );
    }

    public static function magicMethodRemoved(string $className, string $methodName): self
    {
        return new self(
            "Hot-swap source for {$className} removes {$methodName}() which the class entry references "
            . 'as a magic slot - adding or removing magic methods and constructors is not supported',
        );
    }

    public static function constantRemoved(string $className, string $constantName): self
    {
        return new self(
            "Hot-swap source for {$className} removes the constant {$constantName} - "
            . 'constant removal is not supported',
        );
    }

    public static function shutdown(): self
    {
        return new self('Cannot apply a class delta after Core::shutdown()');
    }

    public static function alreadyApplied(): self
    {
        return new self('This class delta has already been applied');
    }

    public static function discarded(): self
    {
        return new self('This class delta has been discarded');
    }

    public static function applyFailedAndRolledBack(string $className, \Throwable $cause): self
    {
        return new self(
            "Hot-swap of {$className} failed and was rolled back: {$cause->getMessage()}",
            0,
            $cause,
        );
    }
}
