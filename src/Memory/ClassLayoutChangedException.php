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

namespace ZEngine\Memory;

/**
 * Raised at re-attachment (first get() of a request) when the current definition of a
 * recorded class produces a different object size (ReflectionClass::getObjectSize) than
 * the one captured at put() time - the stored byte layout would be misread. The graph
 * stays untouched; the only safe operation left for the key is remove().
 */
final class ClassLayoutChangedException extends PersistentHeapException
{
    public static function forClass(string $key, string $className, int $expected, int $actual): self
    {
        return new self(
            "Cannot re-attach persistent heap key \"{$key}\": class {$className} changed layout "
            . "(stored object size {$expected}, current {$actual})",
        );
    }
}
