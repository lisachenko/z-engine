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

use ZEngine\Core;
use ZEngine\Memory\PersistentHeap;
use ZEngine\Type\PersistentHashTable;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

/**
 * Userland graph node local to this scenario process
 */
class ChurnNode
{
    public ?ChurnNode $left = null;

    public ?ChurnNode $right = null;

    public ?ChurnNode $parent = null;

    public string $label = '';

    /** @var array<int|string, mixed> */
    public array $items = [];
}

// Whole put/get/remove lifecycle on a detached registry: on a debug build the leak gate
// fails on any request-allocator block left behind (materialized property caches, zval
// containers, temporary strings)
$heap = new PersistentHeap(PersistentHashTable::create());

for ($cycle = 0; $cycle < 50; $cycle++) {
    $shared        = new ChurnNode();
    $shared->label = 'shared-' . $cycle;

    $root               = new ChurnNode();
    $root->label        = 'root';
    $root->items        = ['limits' => [10, 20], 42 => 'answer'];
    $root->left         = new ChurnNode();
    $root->left->parent = $root;      // cycle
    $root->left->right  = $shared;    // DAG share
    $root->right        = $shared;    // DAG share

    $heap->put('churn', $root);
    unset($root, $shared);
    gc_collect_cycles();

    $alias = $heap->get('churn');
    if ($alias->left->right !== $alias->right || $alias->left->parent !== $alias) {
        throw new RuntimeException('Graph topology lost during churn');
    }
    if ($alias->items !== ['limits' => [10, 20], 42 => 'answer']) {
        throw new RuntimeException('Array payload lost during churn');
    }

    // Materialize the per-request properties cache on purpose: eviction must release it
    get_object_vars($alias);

    unset($alias);
    gc_collect_cycles();

    $heap->remove('churn');
}

if ($heap->stats()['keys'] !== 0) {
    throw new RuntimeException('Heap must be empty after the churn');
}

// Leave one graph in place and dismantle the whole heap through destroy()
$survivor        = new ChurnNode();
$survivor->label = 'survivor';
$heap->put('last', $survivor);
$heap->destroy();

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
