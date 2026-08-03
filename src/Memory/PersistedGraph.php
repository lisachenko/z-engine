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

use FFI\CData;

/**
 * The complete inventory of one persisted object graph
 *
 * Produced by PersistentGraphCloner and stored (as engine-visible metadata tables) by
 * PersistentHeap. The inventory is what makes eviction and integrity checking possible:
 * every malloc block the clone pass minted is listed exactly once, so remove() can free
 * each block exactly once (shared DAG nodes are single entries) and re-attachment can
 * verify by address that every payload a stored slot points at belongs to the graph.
 */
final class PersistedGraph
{
    /**
     * @param CData        $root       zend_object* of the cloned graph root
     * @param list<CData>  $objects    every cloned zend_object*, root included, each exactly once
     * @param list<string> $classNames lowercased class name per object (parallel to $objects)
     * @param list<int>    $classSizes ReflectionClass::getObjectSize() per object at put() time
     * @param list<CData>  $strings    every minted persistent interned zend_string*, each exactly once
     * @param list<CData>  $arrays     every minted persistent HashTable*, each exactly once
     * @param int          $bytes      total payload bytes allocated for the graph (objects,
     *                                 strings and table structs; engine-grown table data
     *                                 blocks are not included)
     */
    public function __construct(
        public readonly CData $root,
        public readonly array $objects,
        public readonly array $classNames,
        public readonly array $classSizes,
        public readonly array $strings,
        public readonly array $arrays,
        public readonly int $bytes,
    ) {}
}
