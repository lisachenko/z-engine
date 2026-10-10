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

namespace ZEngine\Reflection;

use PHPUnit\Framework\TestCase;
use ZEngine\Core;
use ZEngine\OpCache\SharedMemoryException;
use ZEngine\Stub\Alias\CloneAliasBase;
use ZEngine\Stub\Alias\CloneAliasChild;
use ZEngine\Stub\Alias\CloneAliasDonor;
use ZEngine\Stub\Alias\CloneBase;
use ZEngine\Stub\Alias\CloneChild;
use ZEngine\Stub\Alias\CloneContract;
use ZEngine\Stub\Alias\CloneEarlyBase;
use ZEngine\Stub\Alias\CloneEarlyChild;
use ZEngine\Stub\Alias\CloneEarlyDonor;
use ZEngine\Stub\Alias\CloneGrandChild;
use ZEngine\Stub\Alias\CloneOverrideBase;
use ZEngine\Stub\Alias\CloneOverrideChild;
use ZEngine\Stub\Alias\CloneOverrideDonor;
use ZEngine\Stub\Alias\CloneOverrideMid;

/**
 * The engine CLONE of an inherited method entry held by a subclass implementing an interface
 * that declares the method, and the operations that must reach it: redefine() of the ancestor
 * method, its preserved alias, an addMethod() override on an intermediate class, and the
 * shared-memory guards of both publications
 *
 * Inheritance publishes the parent's zend_function POINTER into a subclass method table, so an
 * in-place body swap of the parent entry is visible in every subclass at once. One inheritance
 * shape does not share the pointer: when the subclass implements an interface declaring the
 * inherited method, do_inheritance_check_on_method copies the inherited entry into an
 * arena-allocated struct of its own - same scope and name, the interface method as prototype,
 * the body and the cache pointer of that moment - and publishes THAT in the subclass table
 * (subclasses of the subclass then share the clone's pointer, the ordinary way). Before the fix
 * such a clone was invisible to z-engine, which enumerated holders by pointer identity: the
 * subclass kept the previous body after a redefine() of the ancestor method (and kept reading
 * the run-time cache the next swap freed), an addMethod() override published on an intermediate
 * class left it dispatching the ancestor body, a preserved alias never reached its class, and
 * the shared-memory guards let an opcache-shared ancestor be copied out from under it. Every
 * scenario here pins the dispatch results and the engine state: the identity of the clone
 * (FunctionBodySwap::cloneHolders()), of its body and of its publication.
 *
 * Every scenario owns its stub classes (tests/Stub/Alias/Clone*): a redefinition changes the
 * class for the rest of the process. The scenario whose subclass links AFTER the first swap
 * holds in both runner modes (the ancestor is already copied out of shared memory when the
 * subclass links against it); where "linked before" is the subject, an opcache-shared ancestor
 * either refuses the publication with SharedMemoryException or - for a plain body swap - is
 * copied out while the subclass keeps the shared parent and the clone of the previous body
 * (the copy-out caveats of docs/hot-swap.md), and the scenario pins that outcome instead.
 */
class RedefineInterfaceCloneTest extends TestCase
{
    public function testCloneOfASubclassLinkedAfterTheFirstSwapFollowsTheNextOne(): void
    {
        $entry = (new ReflectionClass(CloneBase::class))->getMethod('m');
        $entry->redefine(fn(string $who): string => 'v1:' . strtoupper($who));
        // Re-read after the swap: under opcache the first swap copied the class out of SHM
        $entry = (new ReflectionClass(CloneBase::class))->getMethod('m');
        self::assertSame('v1:A', (new CloneBase())->m('a'));

        // Autoloaded now, i.e. linked against the redefined ancestor: the interface prototype
        // makes the engine separate the inherited bucket into a struct copy of the entry, and
        // the grandchild inherits that copy's pointer at its own link time
        $child      = new CloneChild();
        $grandChild = new CloneGrandChild();
        self::assertSame('v1:B', $child->m('b'));
        self::assertSame('v1:C', $grandChild->m('c'));

        self::assertSame([CloneBase::class], self::holderNames($entry), 'neither subclass table holds the entry pointer');
        $clone        = self::cloneHeldBy($entry, CloneChild::class);
        $cloneAddress = $clone->getAddress();
        self::assertNotSame($entry->getAddress(), $cloneAddress, 'the subclass publishes a struct copy of the entry');
        self::assertSame((new ReflectionClass(CloneBase::class))->getAddress(), self::scopeAddress($clone), 'the copy keeps the declaring scope');
        self::assertSame((new ReflectionClass(CloneContract::class))->getMethod('m')->getAddress(), self::prototypeAddress($clone), 'the interface method is its prototype');
        self::assertSame(self::opcodesAddress($entry), self::opcodesAddress($clone), 'the copy shares the body of the moment it was made');
        self::assertSame(
            [CloneChild::class, CloneGrandChild::class],
            self::holderNames($clone),
            'the grandchild shares the clone pointer: a swap of the clone owes two body shares',
        );
        self::assertSame($cloneAddress, (new ReflectionClass(CloneGrandChild::class))->getMethod('m')->getAddress());

        // The second swap of the entry: before the fix the clone was not a holder, kept the
        // previous body and the released run-time cache (use-after-free), so both subclasses
        // stayed on v1 while the ancestor dispatched v2
        $entry->redefine(fn(string $who): string => 'v2:' . strtolower($who) . str_repeat('!', 2));
        self::assertSame('v2:a!!', (new CloneBase())->m('A'));
        self::assertSame('v2:b!!', $child->m('B'), 'the subclass dispatches the new body through its clone');
        self::assertSame('v2:c!!', $grandChild->m('C'), 'and so does the grandchild sharing the clone');
        self::assertSame('v2:d!!', (new CloneChild())->m('D'));

        $clone = self::cloneHeldBy($entry, CloneChild::class);
        self::assertSame($cloneAddress, $clone->getAddress(), 'the clone was swapped in place: the subclass buckets are untouched');
        self::assertSame($cloneAddress, (new ReflectionClass(CloneGrandChild::class))->getMethod('m')->getAddress());
        self::assertSame(self::opcodesAddress($entry), self::opcodesAddress($clone), 'the clone executes the entry\'s new body');
        self::assertSame((new ReflectionClass(CloneBase::class))->getAddress(), self::scopeAddress($clone), 'the declaration identity survives the swap');
        self::assertSame((new ReflectionClass(CloneContract::class))->getMethod('m')->getAddress(), self::prototypeAddress($clone));
        self::assertNotSame(self::cacheAddress($entry), self::cacheAddress($clone), 'the clone reads a minted cache of its own, not the entry\'s');
        self::assertSame(0, $clone->getCommonPointer()->fn_flags & Core::ZEND_ACC_HEAP_RT_CACHE, 'a cache shared by two tables is never heap-flagged');
        $native = new \ReflectionMethod(CloneChild::class, 'm');
        self::assertSame(CloneBase::class, $native->getDeclaringClass()->getName());
        self::assertSame(CloneContract::class, $native->getPrototype()->getDeclaringClass()->getName());

        // Warmed inline caches of the clone keep working on the swapped-in body
        $dispatched = '';
        for ($i = 0; $i < 300; $i++) {
            $dispatched = (new CloneChild())->m('z') . (new CloneGrandChild())->m('y');
        }
        self::assertSame('v2:z!!v2:y!!', $dispatched);
    }

    public function testCloneOfASubclassLinkedBeforeFollowsTheRedefinition(): void
    {
        // Linked now, before the redefinition
        $child = new CloneEarlyChild();
        self::assertSame('base:a', $child->m('a'));
        $class = new ReflectionClass(CloneEarlyBase::class);
        $entry = $class->getMethod('m');

        $clone        = self::cloneHeldBy($entry, CloneEarlyChild::class);
        $cloneAddress = $clone->getAddress();
        self::assertNotSame($entry->getAddress(), $cloneAddress);
        self::assertSame(self::opcodesAddress($entry), self::opcodesAddress($clone));

        $isSharedMemoryClass = $class->isImmutable();
        $entry->redefine((new ReflectionClass(CloneEarlyDonor::class))->getMethod('m'));
        self::assertSame('v1:A', (new CloneEarlyBase())->m('a'));

        if ($isSharedMemoryClass) {
            // The declaring class was copied out of shared memory: a subclass linked against
            // the shared entry keeps the shared parent, and with it the clone of the previous
            // body, whose scope is the shared class - not a clone of the copy
            self::assertSame('base:b', $child->m('b'), 'the copy-out does not reach a subclass linked before');
            self::assertSame([], FunctionBodySwap::cloneHolders((new ReflectionClass(CloneEarlyBase::class))->getMethod('m')));
            self::assertSame($cloneAddress, (new ReflectionClass(CloneEarlyChild::class))->getMethod('m')->getAddress());

            return;
        }

        // Before the fix only the pointer-sharing buckets followed: the subclass kept 'base:'
        self::assertSame('v1:B', $child->m('b'), 'the clone linked before follows the swap of the entry');
        self::assertSame('v1:C', (new CloneEarlyChild())->m('c'));

        $clone = self::cloneHeldBy($entry, CloneEarlyChild::class);
        self::assertSame($cloneAddress, $clone->getAddress(), 'swapped in place: the subclass bucket is untouched');
        self::assertSame(self::opcodesAddress($entry), self::opcodesAddress($clone), 'the clone executes the donor body');
        self::assertNotSame(self::cacheAddress($entry), self::cacheAddress($clone), 'with a minted cache of its own');
        // The donor class bucket, the entry bucket and the clone bucket each own one share of
        // the body: fewer would free it early at shutdown, more would leak it
        $refCountPointer = $clone->getOpArrayPointer()->refcount;
        self::assertNotNull($refCountPointer, 'a runtime donor body is refcounted');
        self::assertSame(3, $refCountPointer[0]);
        self::assertSame(Core::addressOf($refCountPointer), Core::addressOf(self::refCountCell($entry)), 'one body, one counter cell');
    }

    public function testPreservedAliasReachesTheCloneHolderLinkedBefore(): void
    {
        // Linked now: the only subclass is the one holding a clone, which the pointer-identity
        // guard of the old code did not see
        $child = new CloneAliasChild();
        self::assertSame('base:a', $child->m('a'));
        $class = new ReflectionClass(CloneAliasBase::class);
        self::assertSame([CloneAliasBase::class], self::holderNames($class->getMethod('m')));
        self::assertSame(CloneAliasChild::class, self::cloneHolderNames($class->getMethod('m'))[0] ?? null);

        if ($class->isImmutable()) {
            // The subclass linked against the shared-memory entry could never see the alias
            // the swapped-in body calls: the guard refuses before anything is copied out
            $this->expectException(SharedMemoryException::class);
            $this->expectExceptionMessageMatches('/subclass .*CloneAliasChild was linked against the shared entry/');
        }
        $class->getMethod('m')->redefine((new ReflectionClass(CloneAliasDonor::class))->getMethod('m'), preserveAs: 'mOriginal');

        self::assertSame('[base:a]', (new CloneAliasBase())->m('a'));
        // The clone dispatches the new body on the subclass instance, and that body resolves
        // the private alias in the SUBCLASS table (zend_std_get_method looks there first)
        self::assertSame('[base:b]', $child->m('b'), 'the swapped-in body reaches the alias on a subclass instance');
        self::assertSame('[base:c]', (new CloneAliasChild())->m('c'));

        self::assertTrue((new \ReflectionClass(CloneAliasChild::class))->hasMethod('mOriginal'), 'the alias was shared into the subclass table');
        $alias = (new ReflectionClass(CloneAliasBase::class))->getMethod('mOriginal');
        self::assertSame([CloneAliasBase::class, CloneAliasChild::class], self::holderNames($alias), 'one alias container, published by both tables');
        $native = new \ReflectionMethod(CloneAliasChild::class, 'mOriginal');
        self::assertTrue($native->isPrivate());
        self::assertSame(CloneAliasBase::class, $native->getDeclaringClass()->getName());
        self::assertSame('base:d', $native->invoke($child, 'd'), 'the preserved body runs on the subclass instance');
    }

    public function testOverrideOnAnIntermediateClassReplacesTheCloneLinkedBefore(): void
    {
        // The whole chain is linked now: the middle class shares the ancestor's entry pointer,
        // the leaf implementing the interface holds a clone of it
        $child = new CloneOverrideChild();
        self::assertSame('base:a', $child->m('a'));
        $middle    = new ReflectionClass(CloneOverrideMid::class);
        $baseEntry = (new ReflectionClass(CloneOverrideBase::class))->getMethod('m');
        self::assertSame([CloneOverrideBase::class, CloneOverrideMid::class], self::holderNames($baseEntry));
        self::assertSame([CloneOverrideChild::class], self::cloneHolderNames($baseEntry));

        if ($middle->isImmutable()) {
            // The leaf linked against the shared entry of the middle class would keep inheriting
            // the ancestor's declaration: the guard counts the clone holder like a pointer holder
            $this->expectException(SharedMemoryException::class);
            $this->expectExceptionMessageMatches('/subclass .*CloneOverrideChild was linked against the shared entry/');
        }
        $published = $middle->addMethod('m', (new ReflectionClass(CloneOverrideDonor::class))->getMethod('m'));

        self::assertSame('over+base:b', (new CloneOverrideMid())->m('b'));
        // Before the fix the leaf kept its clone of the ancestor entry and answered 'base:c'
        self::assertSame('over+base:c', $child->m('c'), 'the leaf linked before dispatches the override');
        self::assertSame('over+base:d', (new CloneOverrideChild())->m('d'));
        self::assertSame('base:e', (new CloneOverrideBase())->m('e'), 'the ancestor is untouched');

        // The clone bucket was replaced by the published override itself (zend_duplicate_function
        // semantics, like a pointer-sharing bucket), so no clone of the ancestor entry remains
        self::assertSame($published->getAddress(), (new ReflectionClass(CloneOverrideChild::class))->getMethod('m')->getAddress());
        self::assertSame([CloneOverrideChild::class, CloneOverrideMid::class], self::holderNames($published));
        self::assertSame([], self::cloneHolderNames($baseEntry));
        self::assertSame([CloneOverrideBase::class], self::holderNames($baseEntry), 'the ancestor keeps its own bucket only');
        $native = new \ReflectionMethod(CloneOverrideChild::class, 'm');
        self::assertSame(CloneOverrideMid::class, $native->getDeclaringClass()->getName());
        self::assertSame(CloneOverrideBase::class, $native->getPrototype()->getDeclaringClass()->getName(), 'the override keeps the inherited prototype');
    }

    /**
     * The clone of $entry published in the method table of $holderClass (see FunctionBodySwap::cloneHolders())
     */
    private static function cloneHeldBy(FunctionLikeInterface $entry, string $holderClass): ReflectionMethod
    {
        foreach (FunctionBodySwap::cloneHolders($entry) as [$holder, $clone]) {
            if ($holder->getName() === $holderClass) {
                return $clone;
            }
        }
        self::fail("{$holderClass} holds no clone of the entry");
    }

    /**
     * Sorted names of the classes whose method tables publish the entry pointer itself
     *
     * @return list<string>
     */
    private static function holderNames(FunctionLikeInterface $entry): array
    {
        $names = array_map(
            static fn(ReflectionClass $holder): string => $holder->getName(),
            FunctionBodySwap::publishedShareHolders($entry),
        );
        sort($names);

        return $names;
    }

    /**
     * Sorted names of the classes whose method tables publish a clone of the entry
     *
     * @return list<string>
     */
    private static function cloneHolderNames(FunctionLikeInterface $entry): array
    {
        $names = array_map(
            static fn(array $holderAndClone): string => $holderAndClone[0]->getName(),
            FunctionBodySwap::cloneHolders($entry),
        );
        sort($names);

        return $names;
    }

    private static function scopeAddress(FunctionLikeInterface $function): int
    {
        $scope = $function->getCommonPointer()->scope;
        self::assertNotNull($scope, 'a method entry always carries its declaring scope');

        return Core::addressOf($scope);
    }

    private static function prototypeAddress(FunctionLikeInterface $function): int
    {
        $prototype = $function->getCommonPointer()->prototype;
        self::assertNotNull($prototype, 'the entry is expected to carry a prototype');

        return Core::addressOf($prototype);
    }

    /**
     * Address of the opcodes array: two entries with the same address execute the same body
     */
    private static function opcodesAddress(FunctionLikeInterface $function): int
    {
        $opcodes = $function->getOpArrayPointer()->opcodes;
        self::assertNotNull($opcodes, 'a user function always has opcodes');

        return Core::addressOf($opcodes);
    }

    /**
     * Address of the run-time cache block a user function reads its inline caches from
     */
    private static function cacheAddress(FunctionLikeInterface $function): int
    {
        $cache = $function->getOpArrayPointer()->run_time_cache__ptr;
        self::assertNotNull($cache, 'a swapped entry is never left without a run-time cache');

        return Core::addressOf($cache);
    }

    /**
     * The body refcount cell of a refcounted (runtime, non-SHM) user function body
     *
     * @return \FFI\CData
     */
    private static function refCountCell(FunctionLikeInterface $function): object
    {
        $refCountPointer = $function->getOpArrayPointer()->refcount;
        self::assertNotNull($refCountPointer, 'a runtime body is refcounted');

        return $refCountPointer;
    }
}
