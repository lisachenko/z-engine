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
 * Immutable set of slot-addressed type replacements for ClassSpecializer
 *
 * The companion of TypeSubstitutionMap: that one matches on a placeholder *type name* and so
 * can only reach slots that declare a class-like type, while this one addresses the
 * declaration itself and can therefore rewrite a `mixed` or otherwise builtin slot.
 *
 * Replacement types are written the way PHP writes them: `int`, `?int`, `App\User`,
 * `?App\User`. Unlike the name-keyed path, which preserves whatever nullability the template
 * declared, the nullability written here is the nullability the copy gets.
 *
 * ```php
 * $specialized = (new ClassSpecializer())->specialize(Template::class, 'Template@Int', null, new SlotSubstitutionMap([
 *     [TypeSlot::property('value'), 'int'],
 *     [TypeSlot::parameter('setValue', 0), 'int'],
 *     [TypeSlot::returnType('getValue'), '?int'],
 * ]));
 * ```
 */
final class SlotSubstitutionMap
{
    /**
     * @var array<string, string>
     */
    private array $substitutions = [];

    /**
     * @var array<string, true>
     */
    private array $methods = [];

    /**
     * @var list<array{TypeSlot, string}>
     */
    private array $entries;

    /**
     * @param list<array{TypeSlot, string}> $substitutions Slot and the type it should carry
     */
    public function __construct(array $substitutions)
    {
        foreach ($substitutions as [$slot, $replacement]) {
            if ($replacement === '' || $replacement === '?') {
                throw new ClassSpecializationException(
                    'Slot substitutions require a non-empty replacement type name for '
                    . $slot->describe('the source class'),
                );
            }
            $key = $slot->key();
            if (isset($this->substitutions[$key])) {
                throw new ClassSpecializationException(
                    'Duplicate slot substitution for ' . $slot->describe('the source class'),
                );
            }
            $this->substitutions[$key] = self::normalize($replacement);
            if ($slot->kind !== TypeSlotKind::Property) {
                $this->methods[strtolower($slot->memberName)] = true;
            }
        }
        $this->entries = array_values($substitutions);
    }

    public function isEmpty(): bool
    {
        return $this->substitutions === [];
    }

    public function resolveProperty(string $propertyName): ?string
    {
        return $this->substitutions[TypeSlot::property($propertyName)->key()] ?? null;
    }

    public function resolveParameter(string $methodName, int $parameterIndex): ?string
    {
        return $this->substitutions[TypeSlot::parameter($methodName, $parameterIndex)->key()] ?? null;
    }

    public function resolveReturnType(string $methodName): ?string
    {
        return $this->substitutions[TypeSlot::returnType($methodName)->key()] ?? null;
    }

    /**
     * Whether any slot of this method is substituted, which is what forces its arg_info block
     * to be duplicated instead of shared with the template
     */
    public function addressesMethod(string $methodName): bool
    {
        return isset($this->methods[strtolower($methodName)]);
    }

    /**
     * @return list<array{TypeSlot, string}>
     */
    public function toList(): array
    {
        return $this->entries;
    }

    /**
     * Strips a leading `\` from the class part while keeping the `?` marker in front
     */
    private static function normalize(string $replacement): string
    {
        if (str_starts_with($replacement, '?')) {
            return '?' . ltrim(substr($replacement, 1), '\\');
        }

        return ltrim($replacement, '\\');
    }
}
