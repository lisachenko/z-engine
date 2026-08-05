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
 * Addresses one declaration slot of a class by name and position rather than by type name
 *
 * TypeSubstitutionMap can only rewrite a type it can *name*, which rules out every slot
 * declared as a builtin: `mixed` has no name to match on. A slot address reaches those, and
 * is also the only way to rewrite two identically-typed slots differently.
 */
final class TypeSlot
{
    private function __construct(
        public readonly TypeSlotKind $kind,
        public readonly string $memberName,
        public readonly ?int $parameterIndex = null,
    ) {}

    public static function property(string $propertyName): self
    {
        return new self(TypeSlotKind::Property, $propertyName);
    }

    /**
     * @param int $parameterIndex Zero-based declaration position of the parameter
     */
    public static function parameter(string $methodName, int $parameterIndex): self
    {
        return new self(TypeSlotKind::Parameter, $methodName, $parameterIndex);
    }

    public static function returnType(string $methodName): self
    {
        return new self(TypeSlotKind::ReturnType, $methodName);
    }

    /**
     * Normalized lookup key
     *
     * Method names are lowercased because the engine's function_table is keyed that way and
     * PHP method names are case-insensitive; property names are not, because the engine's
     * properties_info table is case-sensitive.
     */
    public function key(): string
    {
        return match ($this->kind) {
            TypeSlotKind::Property   => 'property:' . $this->memberName,
            TypeSlotKind::Parameter  => 'parameter:' . strtolower($this->memberName) . ':' . $this->parameterIndex,
            TypeSlotKind::ReturnType => 'return:' . strtolower($this->memberName),
        };
    }

    /**
     * Renders the slot the way a PHP error message would name it
     */
    public function describe(string $className): string
    {
        return match ($this->kind) {
            TypeSlotKind::Property   => "property {$className}::\${$this->memberName}",
            TypeSlotKind::Parameter  => "parameter #{$this->parameterIndex} of {$className}::{$this->memberName}()",
            TypeSlotKind::ReturnType => "return type of {$className}::{$this->memberName}()",
        };
    }
}
