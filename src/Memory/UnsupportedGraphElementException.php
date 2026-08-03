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
 * Raised at put() time when the object graph contains a value outside the supported-type
 * matrix (docs/persistent-heap.md). Thrown BEFORE any persistent allocation happens, so a
 * rejected put() leaves no partial graph behind.
 */
final class UnsupportedGraphElementException extends PersistentHeapException
{
    public static function closure(string $path): self
    {
        return new self(
            "Unsupported value at {$path}: closures capture request state "
            . '(scope, bound object, compiled op_array) and cannot survive the request',
        );
    }

    public static function internalClass(string $className, string $path): self
    {
        return new self(
            "Unsupported object of internal class {$className} at {$path}: internal classes "
            . 'use custom object handlers and engine-owned storage that a byte clone cannot preserve',
        );
    }

    public static function enumCase(string $className, string $path): self
    {
        return new self(
            "Unsupported enum case of {$className} at {$path}: enum cases are engine-managed "
            . 'singletons whose identity cannot be preserved by cloning',
        );
    }

    public static function lazyObject(string $className, string $path): self
    {
        return new self(
            "Unsupported lazy object of class {$className} at {$path}: lazy ghosts and proxies "
            . 'reference request-lifetime initializers',
        );
    }

    public static function customHandlers(string $className, string $path): self
    {
        return new self(
            "Unsupported object of class {$className} at {$path}: it does not use the engine's "
            . 'std_object_handlers block; a pointer to any other handlers block would dangle '
            . 'in the next request',
        );
    }

    public static function propertyGuards(string $className, string $path): self
    {
        return new self(
            "Unsupported object of class {$className} at {$path}: classes with magic property "
            . 'accessors (__get/__set/__isset/__unset) carry a request-lifetime guard slot '
            . 'that a byte clone cannot preserve',
        );
    }

    public static function dynamicProperties(string $className, string $path): self
    {
        return new self(
            "Unsupported object of class {$className} at {$path}: it carries dynamic properties, "
            . 'which live outside the fixed inline property table',
        );
    }

    public static function resource(string $path): self
    {
        return new self(
            "Unsupported value at {$path}: resources wrap engine-managed request state "
            . '(file descriptors, streams) that cannot survive the request',
        );
    }

    public static function reference(string $path): self
    {
        return new self(
            "Unsupported value at {$path}: references (by-ref bindings) alias request-lifetime "
            . 'zend_reference containers',
        );
    }

    public static function unsupportedType(string $typeName, string $path): self
    {
        return new self("Unsupported value of engine type {$typeName} at {$path}");
    }
}
