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
 * Raised at re-attachment (first get() of a request) when a class recorded for the stored
 * graph is not present in the current request's class table. The graph stays untouched:
 * load the class (or preload it) and retry, or remove() the key.
 */
final class MissingClassException extends PersistentHeapException
{
    public static function forClass(string $key, string $className): self
    {
        return new self(
            "Cannot re-attach persistent heap key \"{$key}\": class {$className} is not defined "
            . 'in this request',
        );
    }
}
