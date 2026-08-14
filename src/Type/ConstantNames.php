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

namespace ZEngine\Type;

use ReflectionClass;
use ReflectionClassConstant;

/**
 * Shared reverse lookup from an engine number back to the class constant that declares it
 *
 * Several engine-facing classes render a raw value as a name (ReflectionValue::name(),
 * OpLine::typeName(), LiveRange::kindName(), NodeKind::name(), OpCode::name()). Each used
 * to build and cache that reversed map itself, with a different idiom every time and - more
 * importantly - over an UNFILTERED getConstants(): private implementation constants such as
 * NodeKind's AST_*_SHIFT ended up in the map and were reported as if they were engine
 * values. This helper owns the mapping instead: it reads only PUBLIC constants, caches per
 * (class, filter) pair, and leaves the miss behaviour - throwing or a placeholder - to each
 * call site, which is where the two differ on purpose.
 *
 * @internal
 */
final class ConstantNames
{
    /**
     * Reversed constant maps, keyed by the class and filter they were built for
     *
     * @var array<string, array<int, string>>
     */
    private static array $reversedConstants = [];

    /**
     * Returns the "constant value => constant name" map of the given class
     *
     * A value declared twice resolves to the LAST declaration, exactly like the
     * array_flip() calls this replaces - the zval type ids reuse their numbers on purpose.
     *
     * @param class-string $className Class whose public constants are reversed
     * @param string       $prefix    Optional name prefix the constants must carry
     * @param list<string> $excluded  Names that are masks/flags rather than values
     *
     * @return array<int, string>
     */
    public static function of(string $className, string $prefix = '', array $excluded = []): array
    {
        $cacheKey = $className . '|' . $prefix . '|' . implode(',', $excluded);
        if (isset(self::$reversedConstants[$cacheKey])) {
            return self::$reversedConstants[$cacheKey];
        }

        $names = [];
        foreach ((new ReflectionClass($className))->getConstants(ReflectionClassConstant::IS_PUBLIC) as $name => $value) {
            if (!is_int($value) || in_array($name, $excluded, true)) {
                continue;
            }
            if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                continue;
            }
            $names[$value] = $name;
        }

        return self::$reversedConstants[$cacheKey] = $names;
    }
}
