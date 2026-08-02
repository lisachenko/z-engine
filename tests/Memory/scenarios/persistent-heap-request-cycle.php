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
use ZEngine\EngineExtension\ExtensionManager;
use ZEngine\EngineExtension\ExtensionNotRegisteredException;
use ZEngine\EngineExtension\ZEngineModule;
use ZEngine\Memory\HeapInertException;
use ZEngine\Memory\PersistentHeap;

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
// "Request A": explicit bootstrap of the single z-engine module, then persist a graph
// with DAG shares and a cycle. No hidden initialization: before the module is
// registered, the global heap accessor must refuse.
// ---------------------------------------------------------------------------------------
try {
    PersistentHeap::global();
    fwrite(STDERR, "FAILED: global() must refuse before the module is registered\n");
    exit(1);
} catch (ExtensionNotRegisteredException) {
    echo 'no-silent-bootstrap', PHP_EOL;
}

$module = ExtensionManager::register(new ZEngineModule());
$expect(ExtensionManager::has(ZEngineModule::class), 'module-registered');
$expect(ExtensionManager::get(ZEngineModule::class) === $module, 'module-is-singleton');
$expect(extension_loaded('zengine'), 'engine-entry-visible');

$heap = PersistentHeap::global();
$expect($heap === $module->heap(), 'global-is-module-heap');

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

$heapB  = PersistentHeap::global();
$aliasB = $heapB->get('cycle-graph');

$expect($aliasB instanceof RequestCycleNode, 'readable-after-rinit');
assert($aliasB instanceof RequestCycleNode);
$rootB = $aliasB;
$leftB = $rootB->left;
assert($leftB instanceof RequestCycleNode);
$rightB = $rootB->right;
assert($rightB instanceof RequestCycleNode);
$sharedB = $leftB->shared;
assert($sharedB instanceof RequestCycleNode);

$expect($rootB->describe() === 'root:1', 'method-dispatch-works');
$expect($leftB->parent === $rootB, 'cycle-back-edge-intact');
$expect($sharedB === $rightB->shared, 'dag-share-intact');
$expect($sharedB->label === 'shared-leaf' && $sharedB->hits === 7, 'shared-leaf-data-intact');
$expect($rootB->config === ['mode' => 'worker', 'limits' => [10, 20], 42 => 'answer'], 'array-data-intact');
$expect($rootB === $heapB->get('cycle-graph'), 'alias-identity-within-request');
$expect(spl_object_id($rootB) !== spl_object_id($leftB), 'fresh-distinct-handles');

// The collector must neither crash on nor reclaim the persistent region
gc_collect_cycles();
$expect($leftB->parent === $rootB, 'graph-survives-gc');

// Eviction still works in the later request; every alias must be released first
unset($rootB, $leftB, $rightB, $sharedB, $aliasB);
gc_collect_cycles();
$heapB->remove('cycle-graph');
$expect($heapB->stats()['keys'] === 0, 'evicted-in-request-b');

// The module section of phpinfo() reports the live heap statistics (info_func wiring)
$heapB->put('info-graph', new RequestCycleNode());
ob_start();
phpinfo(INFO_MODULES);
$info = (string) ob_get_clean();
$expect(str_contains($info, 'zengine'), 'phpinfo-has-module-section');
$expect(str_contains($info, 'Persistent heap => active'), 'phpinfo-heap-active');
$expect(str_contains($info, 'Persistent heap keys => 1'), 'phpinfo-heap-stats');
$heapB->remove('info-graph');

echo 'SCENARIO OK', PHP_EOL;
