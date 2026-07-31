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

namespace ZEngine\Stub;

use ZEngine\ClassExtension\Hook\CountElementsHook;
use ZEngine\ClassExtension\Hook\HasDimensionHook;
use ZEngine\ClassExtension\Hook\ReadDimensionHook;
use ZEngine\ClassExtension\Hook\UnsetDimensionHook;
use ZEngine\ClassExtension\Hook\WriteDimensionHook;
use ZEngine\ClassExtension\ObjectCountElementsInterface;
use ZEngine\ClassExtension\ObjectCreateInterface;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\ClassExtension\ObjectHasDimensionInterface;
use ZEngine\ClassExtension\ObjectReadDimensionInterface;
use ZEngine\ClassExtension\ObjectUnsetDimensionInterface;
use ZEngine\ClassExtension\ObjectWriteDimensionInterface;

/**
 * Collection with native array-access and count() support, without implementing ArrayAccess
 *
 * \Countable is implemented (with a sentinel value) because debug PHP builds verify
 * count()'s arginfo ("Countable|array") before consulting the count_elements handler,
 * see the ObjectCountElementsInterface documentation. The dimension handlers need no
 * such escort: $collection[...] operations are plain VM opcodes without arginfo checks.
 */
class NativeCollection implements
    ObjectCreateInterface,
    ObjectReadDimensionInterface,
    ObjectWriteDimensionInterface,
    ObjectHasDimensionInterface,
    ObjectUnsetDimensionInterface,
    ObjectCountElementsInterface,
    \Countable
{
    use ObjectCreateTrait;

    /**
     * @var array<array-key, mixed>
     */
    private array $items;

    /**
     * @param array<array-key, mixed> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Only reached when the count_elements hook is NOT installed: the engine checks the
     * handler first. The sentinel value lets tests tell the two code paths apart.
     */
    public function count(): int
    {
        return PHP_INT_MAX;
    }

    /**
     * @inheritDoc
     */
    public static function __dimensionRead(ReadDimensionHook $hook)
    {
        $collection = $hook->getObject();
        $offset     = $hook->getOffset();
        assert($collection instanceof self && (is_int($offset) || is_string($offset)));

        return $collection->items[$offset] ?? null;
    }

    /**
     * @inheritDoc
     */
    public static function __dimensionWrite(WriteDimensionHook $hook): void
    {
        $collection = $hook->getObject();
        $offset     = $hook->getOffset();
        assert($collection instanceof self && ($offset === null || is_int($offset) || is_string($offset)));
        if ($offset === null) {
            // Append operation: $collection[] = $value
            $collection->items[] = $hook->getValue();
        } else {
            $collection->items[$offset] = $hook->getValue();
        }
    }

    /**
     * @inheritDoc
     */
    public static function __dimensionHas(HasDimensionHook $hook): int
    {
        $collection = $hook->getObject();
        $offset     = $hook->getOffset();
        assert($collection instanceof self && (is_int($offset) || is_string($offset)));
        if ($hook->getCheckType() === 1) {
            // empty() check
            return (int) !empty($collection->items[$offset]);
        }

        return (int) isset($collection->items[$offset]);
    }

    /**
     * @inheritDoc
     */
    public static function __dimensionUnset(UnsetDimensionHook $hook): void
    {
        $collection = $hook->getObject();
        $offset     = $hook->getOffset();
        assert($collection instanceof self && (is_int($offset) || is_string($offset)));
        unset($collection->items[$offset]);
    }

    /**
     * @inheritDoc
     */
    public static function __count(CountElementsHook $hook): int
    {
        $collection = $hook->getObject();
        assert($collection instanceof self);

        return count($collection->items);
    }
}
