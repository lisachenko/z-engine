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
 * The fixed slots of a persistent key's descriptor table
 *
 * Unlike almost every other numeric constant in z-engine these values mirror no engine ABI:
 * the descriptor table is z-engine's own on-heap layout, written and read back only by
 * PersistentHeap. The backing values are therefore the integer keys the descriptor's inventory
 * entries are stored under, and they must stay stable because a persistent region survives the
 * process that wrote it.
 *
 * @see PersistentHeap
 */
enum DescriptorSlot: int
{
    /** The graph root object (IS_PTR to a zend_object) */
    case Root = 0;

    /** Inventory table of every cloned object in the graph */
    case Objects = 1;

    /** Class names of the cloned objects, positionally aligned with Objects */
    case ObjectClasses = 2;

    /** Allocation sizes of the cloned objects, positionally aligned with Objects */
    case ObjectSizes = 3;

    /** Inventory table of every cloned string */
    case Strings = 4;

    /** Inventory table of every cloned array */
    case Arrays = 5;

    /** Recorded payload byte count of the whole graph (IS_LONG) */
    case Bytes = 6;
}
