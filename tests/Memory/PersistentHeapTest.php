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

use PHPUnit\Framework\TestCase;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Stub\DebuggableCloneable;
use ZEngine\Stub\TestDynamicPropsHolder;
use ZEngine\Stub\TestGraphNode;
use ZEngine\Stub\TestPureEnum;
use ZEngine\Type\PersistentHashTable;
use ZEngine\Type\StringEntry;

/**
 * PersistentHeap behavior on a caller-provided registry (no module anchor needed):
 * graph fidelity, DAG/cycle preservation, the enforced supported-type matrix, typed
 * re-attachment failures, eviction and the leak plateau. The module-anchored request
 * cycle is covered by PersistentHeapRequestCycleTest.
 */
class PersistentHeapTest extends TestCase
{
    /**
     * Descriptor slot indexes, mirroring the private PersistentHeap constants
     * (used by the metadata-tampering tests below)
     */
    private const SLOT_OBJECT_CLASSES = 2;
    private const SLOT_OBJECT_SIZES   = 3;

    private PersistentHashTable $registry;

    private PersistentHeap $heap;

    protected function setUp(): void
    {
        $this->registry = PersistentHashTable::create();
        $this->heap     = new PersistentHeap($this->registry);
    }

    public function testRoundTripPreservesScalarsStringsAndArrays(): void
    {
        $node          = new TestGraphNode();
        $node->name    = 'round-trip';
        $node->rank    = 42;
        $node->weight  = 6.5;
        $node->active  = true;
        $node->items   = ['alpha' => 'a', 7 => 'seven', 'nested' => [1, 2, ['deep' => true]], 'empty' => []];
        $node->payload = 'payload-string';

        $this->heap->put('scalars', $node);
        unset($node);

        $alias = $this->heap->get('scalars');

        $this->assertInstanceOf(TestGraphNode::class, $alias);
        $this->assertSame('round-trip', $alias->name);
        $this->assertSame(42, $alias->rank);
        $this->assertSame(6.5, $alias->weight);
        $this->assertTrue($alias->active);
        $this->assertSame(
            ['alpha' => 'a', 7 => 'seven', 'nested' => [1, 2, ['deep' => true]], 'empty' => []],
            $alias->items,
        );
        $this->assertSame('payload-string', $alias->payload);
        $this->assertNull($alias->left);
        // The uninitialized typed property stays uninitialized (IS_UNDEF byte copy)
        $this->assertFalse(isset($alias->tag));
        // The graph is fully functional, not just readable
        $this->assertSame('round-trip#42', $alias->describe());

        unset($alias);
        $this->heap->remove('scalars');
    }

    public function testSharedDagNodesStaySharedAndCyclesDoNotRecurse(): void
    {
        $shared       = new TestGraphNode();
        $shared->name = 'shared';

        $root                = new TestGraphNode();
        $root->name          = 'root';
        $root->left          = new TestGraphNode();
        $root->left->parent  = $root;    // back-edge to the root
        $root->left->right   = $shared;  // shared sub-object, edge one
        $root->right         = new TestGraphNode();
        $root->right->right  = $shared;  // shared sub-object, edge two
        $root->right->parent = $root;    // second back-edge
        $root->payload       = $root;    // self-reference

        $this->heap->put('dag', $root);
        unset($root, $shared);
        gc_collect_cycles();

        $alias = $this->heap->get('dag');

        $this->assertInstanceOf(TestGraphNode::class, $alias);
        $this->assertSame($alias, $alias->left->parent);
        $this->assertSame($alias, $alias->right->parent);
        $this->assertSame($alias, $alias->payload);
        $this->assertSame($alias->left->right, $alias->right->right);
        $this->assertSame('shared', $alias->left->right->name);

        // Shared nodes are cloned exactly once: root, left, right, shared
        $this->assertSame(4, $this->heap->stats()['perKey']['dag']['objects']);

        // The collector must not traverse (or reclaim) the persistent region
        gc_collect_cycles();
        $this->assertSame($alias, $alias->left->parent);

        unset($alias);
        gc_collect_cycles();
        $this->heap->remove('dag');
    }

    public function testSharedArraysAreClonedOnce(): void
    {
        $shared = ['config' => 'value'];

        $root          = new TestGraphNode();
        $root->items   = $shared;
        $root->payload = $shared; // same zend_array shared through copy-on-write

        $this->heap->put('shared-array', $root);

        $this->assertSame(1, $this->heap->stats()['perKey']['shared-array']['arrays']);

        $alias = $this->heap->get('shared-array');
        $this->assertSame(['config' => 'value'], $alias->items);
        $this->assertSame(['config' => 'value'], $alias->payload);

        unset($alias);
        $this->heap->remove('shared-array');
    }

    public function testAliasesAreIdenticalWithinARequestAndDistinctFromTheSource(): void
    {
        $source       = new TestGraphNode();
        $source->name = 'identity';

        $this->heap->put('identity', $source);

        $first  = $this->heap->get('identity');
        $second = $this->heap->get('identity');

        $this->assertSame($first, $second);
        $this->assertNotSame($source, $first);
        // The source object is untouched and stays a normal request object
        $source->name = 'still-mutable';
        $this->assertSame('identity', $first->name);

        unset($first, $second);
        $this->heap->remove('identity');
    }

    public function testGetReturnsNullForMissingKeyAndRemoveThrows(): void
    {
        $this->assertNull($this->heap->get('no-such-key'));

        $this->expectException(HeapKeyNotFoundException::class);
        $this->heap->remove('no-such-key');
    }

    public function testPutOverwritesExistingKeyByEvictingTheOldGraph(): void
    {
        $first       = new TestGraphNode();
        $first->rank = 1;
        $this->heap->put('overwrite', $first);

        $second       = new TestGraphNode();
        $second->rank = 2;
        $this->heap->put('overwrite', $second);

        $alias = $this->heap->get('overwrite');
        $this->assertSame(2, $alias->rank);
        $this->assertSame(1, $this->heap->stats()['keys']);

        unset($alias);
        $this->heap->remove('overwrite');
    }

    public function testEvictionRefusesWhileAliasesAreAlive(): void
    {
        $this->heap->put('in-use', new TestGraphNode());

        $alias = $this->heap->get('in-use');
        try {
            $this->heap->remove('in-use');
            $this->fail('remove() must refuse while an alias is alive');
        } catch (HeapInUseException) {
            // expected: the graph stays valid and readable
            $this->assertInstanceOf(TestGraphNode::class, $alias);
        }

        unset($alias);
        $this->heap->remove('in-use');
        $this->assertNull($this->heap->get('in-use'));
    }

    public function testStatsReportTotalsAndPerKeyBreakdown(): void
    {
        $withStrings       = new TestGraphNode();
        $withStrings->name = 'first-key';

        $withChild       = new TestGraphNode();
        $withChild->left = new TestGraphNode();

        $this->heap->put('stats-a', $withStrings);
        $this->heap->put('stats-b', $withChild);

        $stats = $this->heap->stats();

        $this->assertSame(2, $stats['keys']);
        $this->assertSame(['stats-a', 'stats-b'], array_keys($stats['perKey']));
        $this->assertSame(1, $stats['perKey']['stats-a']['objects']);
        $this->assertSame(2, $stats['perKey']['stats-b']['objects']);
        $this->assertSame($stats['perKey']['stats-a']['objects'] + $stats['perKey']['stats-b']['objects'], $stats['objects']);
        $this->assertGreaterThan(0, $stats['perKey']['stats-a']['strings']);
        $this->assertGreaterThan(0, $stats['bytes']);
        $this->assertSame($stats['perKey']['stats-a']['bytes'] + $stats['perKey']['stats-b']['bytes'], $stats['bytes']);

        $this->heap->remove('stats-a');
        $this->heap->remove('stats-b');
        $this->assertSame(0, $this->heap->stats()['keys']);
    }

    public function testDestroyEvictsEverythingAndInvalidatesTheWrapper(): void
    {
        $registry = PersistentHashTable::create();
        $heap     = new PersistentHeap($registry);

        $heap->put('first', new TestGraphNode());
        $heap->put('second', new TestGraphNode());

        $heap->destroy();

        $this->expectException(PersistentHeapException::class);
        $this->expectExceptionMessage('destroyed');
        $heap->get('first');
    }

    public function testScalarMutationsSurviveReattachment(): void
    {
        $node       = new TestGraphNode();
        $node->rank = 1;
        $this->heap->put('scalar-mutation', $node);

        $alias         = $this->heap->get('scalar-mutation');
        $alias->rank   = 99;          // self-contained bytes: legal cross-request mutation
        $alias->weight = 0.5;
        unset($alias);

        // A fresh heap wrapper over the same registry re-attaches from scratch,
        // exactly like the next request would
        $nextRequest = new PersistentHeap($this->registry);
        $reattached  = $nextRequest->get('scalar-mutation');

        $this->assertSame(99, $reattached->rank);
        $this->assertSame(0.5, $reattached->weight);

        unset($reattached);
        $nextRequest->remove('scalar-mutation');
    }

    public function testRefcountedMutationIsDetectedAtReattachmentAsCorruption(): void
    {
        $node       = new TestGraphNode();
        $node->name = 'pristine';
        $this->heap->put('mutated', $node);

        $alias       = $this->heap->get('mutated');
        $alias->name = 'request-lifetime-' . uniqid(); // writes a REQUEST string into the persistent slot
        unset($alias);

        $nextRequest = new PersistentHeap($this->registry);
        try {
            $nextRequest->get('mutated');
            $this->fail('A refcounted overwrite must be detected at re-attachment');
        } catch (GraphCorruptedException $exception) {
            $this->assertStringContainsString('mutated', $exception->getMessage());
        }

        // The graph is refused, never returned corrupted - and stays evictable
        $nextRequest->remove('mutated');
        $this->assertNull($nextRequest->get('mutated'));
    }

    public function testMissingClassIsDetectedAtReattachment(): void
    {
        $this->heap->put('missing-class', new TestGraphNode());

        // Tamper the recorded metadata: point the class-name entry of object #0 at a
        // class that does not exist (simulates a class not being defined next request)
        $classesTable = $this->descriptorTable('missing-class', self::SLOT_OBJECT_CLASSES);
        $bogusName    = StringEntry::persistentInterned('zengine\stub\class_that_does_not_exist');
        $bogusRaw     = $bogusName->getRawValue();
        $this->assertNotNull($bogusRaw);
        $entry = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $bogusRaw[0]);
        $classesTable->addIndex(0, $entry);
        $entry->release();

        $nextRequest = new PersistentHeap($this->registry);
        try {
            $nextRequest->get('missing-class');
            $this->fail('A missing class must be detected at re-attachment');
        } catch (MissingClassException $exception) {
            $this->assertStringContainsString('class_that_does_not_exist', $exception->getMessage());
        }

        // Restore the real class name so the graph can be evicted cleanly
        $realName = StringEntry::persistentInterned(strtolower(TestGraphNode::class));
        $realRaw  = $realName->getRawValue();
        $this->assertNotNull($realRaw);
        $entry = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $realRaw[0]);
        $classesTable->addIndex(0, $entry);
        $entry->release();

        $nextRequest->remove('missing-class');

        // The tampering strings above were minted outside the heap inventory
        Core::persistentFree($bogusRaw);
        Core::persistentFree($realRaw);
    }

    public function testChangedClassLayoutIsDetectedAtReattachment(): void
    {
        $this->heap->put('layout', new TestGraphNode());

        // Tamper the recorded object size (simulates a re-compiled class with a
        // different property layout in the next request)
        $sizesTable = $this->descriptorTable('layout', self::SLOT_OBJECT_SIZES);
        $classValue = Core::$executor->classTable->find(strtolower(TestGraphNode::class));
        $this->assertNotNull($classValue);
        $realSize = ReflectionClass::getObjectSize($classValue->getRawClass());

        $tampered = new ReflectionValue($realSize + 16);
        $sizesTable->addIndex(0, $tampered);
        $tampered->release();

        $nextRequest = new PersistentHeap($this->registry);
        try {
            $nextRequest->get('layout');
            $this->fail('A changed class layout must be detected at re-attachment');
        } catch (ClassLayoutChangedException $exception) {
            $this->assertStringContainsString((string) ($realSize + 16), $exception->getMessage());
        }

        // Restore and evict
        $restored = new ReflectionValue($realSize);
        $sizesTable->addIndex(0, $restored);
        $restored->release();

        $nextRequest->remove('layout');
    }

    public function testLeakPlateauOverRepeatedPutGetRemoveCycles(): void
    {
        $residentKiloBytes = static function (): int {
            foreach (file('/proc/self/status') ?: [] as $line) {
                if (str_starts_with($line, 'VmRSS:')) {
                    return (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                }
            }

            return 0;
        };

        if ($residentKiloBytes() === 0) {
            self::markTestSkipped('VmRSS is not readable on this platform');
        }

        $churn = function (int $cycles): void {
            for ($cycle = 0; $cycle < $cycles; $cycle++) {
                $shared       = new TestGraphNode();
                $shared->name = 'leaf-' . ($cycle % 16);

                $root               = new TestGraphNode();
                $root->name         = 'root';
                $root->items        = ['limits' => [10, 20], 'labels' => ['a', 'b', 'c']];
                $root->left         = new TestGraphNode();
                $root->left->parent = $root;
                $root->left->right  = $shared;
                $root->right        = $shared;

                $this->heap->put('churn', $root);

                $alias = $this->heap->get('churn');
                if ($alias->left->right !== $alias->right) {
                    throw new \RuntimeException('DAG share lost during churn');
                }
                unset($alias);

                $this->heap->remove('churn');

                // The SOURCE graph is cyclic PHP garbage ($root <-> $root->left); collect
                // it deterministically so the measurement sees only heap allocations
                unset($root, $shared);
                gc_collect_cycles();
            }
        };

        // Warm up allocator state, interned tables and FFI churn first
        $churn(200);
        $baseline = $residentKiloBytes();

        // 1000 graphs x (4 objects + strings + 3 tables + metadata) is megabytes of
        // malloc traffic: without exact eviction, RSS climbs way past the threshold
        $churn(1_000);

        $this->assertLessThan(
            2_048,
            $residentKiloBytes() - $baseline,
            'Repeated put/get/remove cycles must keep process memory flat',
        );
    }

    // ----------------------------------------------------------------------------------
    // Supported-type matrix: one rejection test per unsupported kind
    // ----------------------------------------------------------------------------------

    public function testRejectsClosureAsRoot(): void
    {
        $this->expectException(UnsupportedGraphElementException::class);
        $this->expectExceptionMessage('closures capture request state');
        $this->heap->put('reject', static fn(): int => 1);
    }

    public function testRejectsClosureInProperty(): void
    {
        $node          = new TestGraphNode();
        $node->payload = static fn(): int => 1;

        $this->expectException(UnsupportedGraphElementException::class);
        $this->expectExceptionMessage('closures capture request state');
        $this->heap->put('reject', $node);
    }

    public function testRejectsResourceInProperty(): void
    {
        $node          = new TestGraphNode();
        $node->payload = fopen('php://memory', 'rb');

        $this->expectException(UnsupportedGraphElementException::class);
        $this->expectExceptionMessage('resources wrap engine-managed request state');
        $this->heap->put('reject', $node);
    }

    public function testRejectsResourceInsideArray(): void
    {
        $node        = new TestGraphNode();
        $node->items = ['stream' => fopen('php://memory', 'rb')];

        $this->expectException(UnsupportedGraphElementException::class);
        $this->expectExceptionMessage('[stream]');
        $this->heap->put('reject', $node);
    }

    public function testRejectsReferenceInPropertySlot(): void
    {
        $node = new TestGraphNode();
        $ref  = &$node->rank; // turns the slot into an IS_REFERENCE zval

        $this->expectException(UnsupportedGraphElementException::class);
        $this->expectExceptionMessage('references (by-ref bindings)');
        $this->heap->put('reject', $node);
    }

    public function testRejectsReferenceInsideArray(): void
    {
        $captured    = 1;
        $node        = new TestGraphNode();
        $node->items = ['bound' => &$captured];

        $this->expectException(UnsupportedGraphElementException::class);
        $this->expectExceptionMessage('references (by-ref bindings)');
        $this->heap->put('reject', $node);
    }

    public function testRejectsInternalClassObjects(): void
    {
        $node          = new TestGraphNode();
        $node->payload = new \ArrayObject([1, 2, 3]);

        $this->expectException(UnsupportedGraphElementException::class);
        $this->expectExceptionMessage('internal class ArrayObject');
        $this->heap->put('reject', $node);
    }

    public function testRejectsEnumCases(): void
    {
        $node          = new TestGraphNode();
        $node->payload = TestPureEnum::cases()[0];

        $this->expectException(UnsupportedGraphElementException::class);
        $this->expectExceptionMessage('enum cases are engine-managed singletons');
        $this->heap->put('reject', $node);
    }

    public function testRejectsObjectsWithDynamicProperties(): void
    {
        $holder        = new TestDynamicPropsHolder();
        $holder->extra = 'dynamic'; // @phpstan-ignore property.notFound

        $this->expectException(UnsupportedGraphElementException::class);
        $this->expectExceptionMessage('dynamic properties');
        $this->heap->put('reject', $holder);
    }

    public function testRejectsLazyObjects(): void
    {
        $ghost = (new \ReflectionClass(TestGraphNode::class))->newLazyGhost(static function (): void {});

        $this->expectException(UnsupportedGraphElementException::class);
        $this->expectExceptionMessage('lazy ghosts and proxies');
        $this->heap->put('reject', $ghost);
    }

    public function testRejectsObjectsWithCustomHandlers(): void
    {
        // Installing the extension handlers gives every new instance a per-class
        // handlers block instead of std_object_handlers
        $refClass = new ReflectionClass(DebuggableCloneable::class);
        $refClass->installExtensionHandlers();

        $node          = new TestGraphNode();
        $node->payload = new DebuggableCloneable();

        $this->expectException(UnsupportedGraphElementException::class);
        $this->expectExceptionMessage('std_object_handlers');
        $this->heap->put('reject', $node);
    }

    public function testRejectsObjectsWithMagicPropertyGuards(): void
    {
        $node          = new TestGraphNode();
        $node->payload = new class {
            /** @var array<string, mixed> */
            private array $values = [];

            public function __get(string $name): mixed
            {
                return $this->values[$name] ?? null;
            }

            public function __set(string $name, mixed $value): void
            {
                $this->values[$name] = $value;
            }
        };

        $this->expectException(UnsupportedGraphElementException::class);
        $this->expectExceptionMessage('magic property accessors');
        $this->heap->put('reject', $node);
    }

    public function testRejectedPutLeavesNoPartialGraphBehind(): void
    {
        $node          = new TestGraphNode();
        $node->left    = new TestGraphNode();
        $node->payload = fopen('php://memory', 'rb'); // rejected AFTER valid nodes were walked

        try {
            $this->heap->put('rejected', $node);
            $this->fail('The resource must be rejected');
        } catch (UnsupportedGraphElementException) {
            // expected
        }

        $this->assertNull($this->heap->get('rejected'));
        $this->assertSame(0, $this->heap->stats()['keys']);
    }

    /**
     * Navigates to one metadata table of a stored key (test seam for tampering)
     */
    private function descriptorTable(string $key, int $slot): PersistentHashTable
    {
        $value = $this->registry->find($key);
        $this->assertNotNull($value);
        $descriptor = PersistentHashTable::fromCData(Core::cast('HashTable *', $value->getRawPointer()));

        $slotValue = $descriptor->findIndex($slot);
        $this->assertNotNull($slotValue);

        return PersistentHashTable::fromCData(Core::cast('HashTable *', $slotValue->getRawPointer()));
    }
}
