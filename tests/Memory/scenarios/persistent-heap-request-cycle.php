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
use ZEngine\Memory\HeapInertException;
use ZEngine\Memory\PersistentHeap;
use ZEngine\Memory\PersistentHeapModule;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

/**
 * Userland graph node local to this fixture process
 */
class RequestCycleNode
{
    public ?RequestCycleNode $left = null;

    public ?RequestCycleNode $right = null;

    public ?RequestCycleNode $parent = null;

    public ?RequestCycleNode $shared = null;

    public string $label = '';

    public int $hits = 0;

    /** @var array<int|string, mixed> */
    public array $config = [];

    public function describe(): string
    {
        return "{$this->label}:{$this->hits}";
    }
}

$expect = static function (bool $condition, string $marker): void {
    if (!$condition) {
        fwrite(STDERR, "FAILED: {$marker}\n");
        exit(1);
    }
    echo $marker, PHP_EOL;
};

// ---------------------------------------------------------------------------------------
// "Request A": register the heap module, persist a graph with DAG shares and a cycle
// ---------------------------------------------------------------------------------------
$heap = PersistentHeap::global();

$sharedLeaf        = new RequestCycleNode();
$sharedLeaf->label = 'shared-leaf';
$sharedLeaf->hits  = 7;

$root         = new RequestCycleNode();
$root->label  = 'root';
$root->hits   = 1;
$root->config = ['mode' => 'worker', 'limits' => [10, 20], 42 => 'answer'];

$root->left          = new RequestCycleNode();
$root->left->label   = 'left';
$root->left->parent  = $root;        // back-edge: cycle to the root
$root->left->shared  = $sharedLeaf;  // DAG: shared sub-object
$root->right         = new RequestCycleNode();
$root->right->label  = 'right';
$root->right->shared = $sharedLeaf;  // DAG: same shared sub-object

$heap->put('cycle-graph', $root);
$statsA = $heap->stats();
$expect($statsA['keys'] === 1 && $statsA['perKey']['cycle-graph']['objects'] === 4, 'put-in-request-a');

// The graph is usable within the same request too
$aliasA = $heap->get('cycle-graph');
$expect($aliasA instanceof RequestCycleNode && $aliasA->describe() === 'root:1', 'get-in-request-a');
$expect($aliasA !== $root, 'clone-is-not-the-source');
unset($aliasA, $root, $sharedLeaf);
gc_collect_cycles();

// ---------------------------------------------------------------------------------------
// Simulated RSHUTDOWN/RINIT cycle, delivered through the module lifecycle hooks the way
// an NTS worker manager cycles handled requests inside one live process-level request
// (docs/long-running.md "Runtime models"). The heap must go inert in between, drop all
// per-request state, and recover the graph from the persistent module-globals anchor.
// The REAL request-end ordering (Core::shutdown() first, then the requestShutdown
// delivery) is exercised by this very process exiting at the end of the fixture.
// ---------------------------------------------------------------------------------------
$module = new PersistentHeapModule();
$module->requestShutdown();

try {
    $heap->get('cycle-graph');
    fwrite(STDERR, "FAILED: heap must be inert after request shutdown\n");
    exit(1);
} catch (HeapInertException) {
    echo 'inert-after-rshutdown', PHP_EOL;
}
unset($heap);

$module->requestStartup();

$heapB = PersistentHeap::global();
$rootB = $heapB->get('cycle-graph');

$expect($rootB instanceof RequestCycleNode, 'readable-after-rinit');
$expect($rootB->describe() === 'root:1', 'method-dispatch-works');
$expect($rootB->left->parent === $rootB, 'cycle-back-edge-intact');
$expect($rootB->left->shared === $rootB->right->shared, 'dag-share-intact');
$expect($rootB->left->shared->label === 'shared-leaf' && $rootB->left->shared->hits === 7, 'shared-leaf-data-intact');
$expect($rootB->config === ['mode' => 'worker', 'limits' => [10, 20], 42 => 'answer'], 'array-data-intact');
$expect($rootB === $heapB->get('cycle-graph'), 'alias-identity-within-request');
$expect(spl_object_id($rootB) !== spl_object_id($rootB->left), 'fresh-distinct-handles');

// The collector must neither crash on nor reclaim the persistent region
gc_collect_cycles();
$expect($rootB->left->parent === $rootB, 'graph-survives-gc');

// Eviction still works in the later request
unset($rootB);
gc_collect_cycles();
$heapB->remove('cycle-graph');
$expect($heapB->stats()['keys'] === 0, 'evicted-in-request-b');

echo 'SCENARIO OK', PHP_EOL;
