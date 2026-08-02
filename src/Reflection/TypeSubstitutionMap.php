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

/**
 * Immutable placeholder-to-type map consumed by ClassSpecializer.
 *
 * Keys are placeholder type names as they appear in the template class declaration
 * (class-like names such as "T" or "App\TValue"); values are the concrete types the
 * specialized copy should carry instead - either a builtin scalar type name (int,
 * float, string, bool, true, false, null, array, object, mixed) or a class/interface
 * name. Matching is case-insensitive and ignores a leading namespace backslash, the
 * same rules the engine applies to class-type names.
 */
final class TypeSubstitutionMap
{
    /**
     * Normalized substitutions: lowercased placeholder name => replacement type name
     *
     * @var array<string, string>
     */
    private array $substitutions = [];

    /**
     * @param array<string, string> $substitutions Placeholder type name => replacement type name
     */
    public function __construct(array $substitutions)
    {
        foreach ($substitutions as $placeholder => $replacement) {
            if (!is_string($placeholder) || $placeholder === '' || $replacement === '') {
                throw new ClassSpecializationException(
                    'Type substitutions require non-empty string placeholder and replacement names',
                );
            }
            $normalizedKey = strtolower(ltrim($placeholder, '\\'));
            if ($normalizedKey === '') {
                throw new ClassSpecializationException("Invalid placeholder type name \"{$placeholder}\"");
            }
            $this->substitutions[$normalizedKey] = ltrim($replacement, '\\');
        }
    }

    /**
     * Checks if the map carries no substitutions at all
     */
    public function isEmpty(): bool
    {
        return $this->substitutions === [];
    }

    /**
     * Returns the replacement for the given type name, or null when the name is not a placeholder
     */
    public function resolve(string $typeName): ?string
    {
        return $this->substitutions[strtolower(ltrim($typeName, '\\'))] ?? null;
    }

    /**
     * Returns the normalized map (lowercased placeholder => replacement)
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->substitutions;
    }
}
