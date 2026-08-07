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

use FFI\CData;
use ZEngine\Core;
use ZEngine\EngineExtension\ExtensionManager;
use ZEngine\EngineExtension\ZEngineModule;
use ZEngine\Memory\HeapInertException;
use ZEngine\Memory\PersistentHeap;

require __DIR__ . '/../../../vendor/autoload.php';

/**
 * Userland payload local to this fixture process
 */
class ReinitCyclePayload
{
    public int $answer = 0;
}

$expect = static function (bool $condition, string $marker): void {
    if (!$condition) {
        fwrite(STDERR, "FAILED: {$marker}\n");
        exit(1);
    }
    echo $marker, PHP_EOL;
};

// ---------------------------------------------------------------------------------------
// Core::init() is idempotent per process (issue #108): a repeated call must reuse the
// process-wide FFI binding. Rebinding would free the first binding's type data, turning
// every CData minted against it invalid.
// ---------------------------------------------------------------------------------------
Core::init();
$probe = Core::new('zval');
Core::init();
$probeSlot = $probe->u1;
assert($probeSlot instanceof CData);
$expect($probeSlot->type_info === 0, 'cdata-survives-double-init');

// ---------------------------------------------------------------------------------------
// "Request A": boot the framework module (its entry CData belongs to the first binding)
// and persist a payload behind the module-globals anchor.
// ---------------------------------------------------------------------------------------
$module = ExtensionManager::register(new ZEngineModule());
$heap   = PersistentHeap::global();

$payload         = new ReinitCyclePayload();
$payload->answer = 42;
$heap->put('reinit-key', $payload);
$expect($heap->stats()['keys'] === 1, 'put-in-request-a');

// ---------------------------------------------------------------------------------------
// Full simulated request boundary: unlike the plain RSHUTDOWN/RINIT cycle exercised by
// persistent-heap-request-cycle.php, this one also runs Core::shutdown() + Core::init(),
// the way a worker manager re-boots z-engine inside one live process. Before the fix the
// re-init minted a second FFI binding and the module entry (plus the exit-time
// AbstractModule::deliverRequestShutdown delivery) touched freed first-binding state.
// ---------------------------------------------------------------------------------------
$module->requestShutdown();
try {
    $heap->get('reinit-key');
    fwrite(STDERR, "FAILED: heap must be inert after request shutdown\n");
    exit(1);
} catch (HeapInertException) {
    echo 'inert-after-rshutdown', PHP_EOL;
}
unset($heap);

Core::shutdown();
$expect(Core::isShutdown(), 'core-shutdown-simulated');

Core::init();
$expect(!Core::isShutdown(), 'reinit-done');

// First-binding CData is still alive: the module entry stored in the persistent module
// registry remains readable and the heap recovers from the module-globals anchor
$expect($module->wasModuleStarted(), 'module-entry-valid-after-reinit');

$module->requestStartup();
$heapB     = PersistentHeap::global();
$recovered = $heapB->get('reinit-key');
$expect($recovered instanceof ReinitCyclePayload && $recovered->answer === 42, 'graph-recovered-after-reinit');

// Fresh allocations against the reused binding keep working as well
$fresh     = Core::new('zval');
$freshSlot = $fresh->u1;
assert($freshSlot instanceof CData);
$expect($freshSlot->type_info === 0, 'new-allocations-work-after-reinit');

unset($recovered);
gc_collect_cycles();
$heapB->remove('reinit-key');
$expect($heapB->stats()['keys'] === 0, 'evicted-after-reinit');

// The process exit below replays the REAL request-end ordering on the re-booted state:
// Core::shutdown() first, then the deliverRequestShutdown delivery - both must find
// valid CData (this very line crashing was the issue #108 symptom)
echo 'SCENARIO OK', PHP_EOL;
