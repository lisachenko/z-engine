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
use ZEngine\Stub\Alias\OverridingBase;
use ZEngine\Stub\Alias\OverridingBase2;
use ZEngine\Stub\Alias\OverridingDonor;
use ZEngine\Stub\Alias\OverridingDonor2;
use ZEngine\Stub\Alias\OverridingLateBase;
use ZEngine\Stub\Alias\OverridingLateBase2;
use ZEngine\Stub\Alias\OverridingLateDonor;
use ZEngine\Stub\Alias\OverridingLateDonor2;
use ZEngine\Stub\Alias\OverridingLateLeaf;
use ZEngine\Stub\Alias\OverridingLateMid;
use ZEngine\Stub\Alias\OverridingLateSub;
use ZEngine\Stub\Alias\OverridingLeaf;
use ZEngine\Stub\Alias\OverridingLeaf2;
use ZEngine\Stub\Alias\OverridingMid;
use ZEngine\Stub\Alias\OverridingSub;

/**
 * The preserved alias of redefine(preserveAs:) in the method tables of subclasses
 *
 * A private method of a parent is copied into the method table of every subclass at link
 * time (do_inherit_method -> zend_duplicate_function: the same zend_function pointer, one
 * body share per table), and a subclass declaring a private method of the same name keeps
 * its own entry marked ZEND_ACC_CHANGED, so that the parent's code, running on an instance
 * of the subclass, still resolves the PARENT's private method through
 * zend_get_parent_private_method(). An alias published after the subclasses were linked
 * has to reproduce exactly that: the swapped-in body calls the alias on whatever receiver
 * it runs - also on instances of a subclass that OVERRIDES the redefined method and reaches
 * the base body through parent::, whose table never held the base entry at all. The alias
 * used to be shared only into subclasses whose table pointed at the entry structure, so an
 * overriding subclass ended in "Call to undefined method", and a subclass declaring the
 * alias name itself in "Call to private method" (no CHANGED bit); the refusal for a
 * shared-memory class considered the same too-narrow set of subclasses.
 *
 * Every scenario owns its stub classes (tests/Stub/Alias/Overriding*): a redefinition
 * changes the class for the rest of the process. The "linked before" scenarios are the
 * subject here, so under an opcache-active runner (every stub an immutable shared-memory
 * class) they pin the refusal instead, which must happen BEFORE the copy-out and leave the
 * shared class untouched; the "linked after" scenarios hold in both runner modes.
 */
class RedefineAliasInheritanceTest extends TestCase
{
    public function testAliasReachesOverridingSubclassesLinkedBefore(): void
    {
        // The whole chain is linked now: Sub overrides m() through parent::, Leaf inherits
        // Sub's override - neither table holds the base entry the alias is published for
        self::assertSame('sub:base', (new OverridingLeaf())->m());
        self::assertFalse((new \ReflectionClass(OverridingLeaf::class))->hasMethod('mOriginal'));
        $class = new ReflectionClass(OverridingBase::class);
        $donor = (new ReflectionClass(OverridingDonor::class))->getMethod('m');

        if ($class->isImmutable()) {
            // Refused before the copy-out: the overriding subclass keeps the shared parent
            // and its parent:: call would run the swapped body without the alias
            try {
                $class->getMethod('m')->redefine($donor, preserveAs: 'mOriginal');
                self::fail('alias into a shared class with an overriding subclass linked before not refused');
            } catch (SharedMemoryException $exception) {
                self::assertMatchesRegularExpression('/subclass .*Overriding(Sub|Leaf) was linked against the shared entry/', $exception->getMessage());
            }
            self::assertTrue((new ReflectionClass(OverridingBase::class))->isImmutable(), 'refused before anything was copied out');
            self::assertFalse((new \ReflectionClass(OverridingBase::class))->hasMethod('mOriginal'));
            self::assertSame('sub:base', (new OverridingLeaf())->m(), 'nothing was swapped');

            return;
        }

        $class->getMethod('m')->redefine($donor, preserveAs: 'mOriginal');

        // The swapped-in body resolves $this->mOriginal() on every receiver it runs on
        self::assertSame('[base]', (new OverridingBase())->m());
        self::assertSame('sub:[base]', (new OverridingSub())->m(), 'parent:: from the override runs the new body on a Sub receiver');
        self::assertSame('sub:[base]', (new OverridingLeaf())->m());

        // The alias is in every subclass table as an inherited private method of the base,
        // exactly what the engine copies in at link time
        foreach ([OverridingSub::class, OverridingLeaf::class] as $subclass) {
            self::assertTrue((new \ReflectionClass($subclass))->hasMethod('mOriginal'), $subclass);
            $native = new \ReflectionMethod($subclass, 'mOriginal');
            self::assertSame(OverridingBase::class, $native->getDeclaringClass()->getName(), $subclass);
            self::assertTrue($native->isPrivate(), $subclass);
        }
        self::assertSame(OverridingSub::class, (new \ReflectionMethod(OverridingSub::class, 'm'))->getDeclaringClass()->getName(), 'the override itself is untouched');

        // One structure, published by three tables (zend_duplicate_function semantics): the
        // subclass buckets point at the base's alias container ...
        $alias = (new ReflectionClass(OverridingBase::class))->getMethod('mOriginal');
        self::assertSame($alias->getAddress(), (new ReflectionClass(OverridingSub::class))->getMethod('mOriginal')->getAddress());
        self::assertSame($alias->getAddress(), (new ReflectionClass(OverridingLeaf::class))->getMethod('mOriginal')->getAddress());
        self::assertSame(
            [OverridingBase::class, OverridingLeaf::class, OverridingSub::class],
            self::holderNames($alias),
        );
        // ... and each bucket took the body share its destructor releases at shutdown:
        // the entry's own share was released by the swap, the alias and the two subclass
        // buckets hold one each - fewer would free the preserved body early, more leak it
        $refCountPointer = $alias->getOpArrayPointer()->refcount;
        self::assertNotNull($refCountPointer, 'a redefined runtime body is refcounted');
        self::assertSame(3, $refCountPointer[0]);

        $flags = $alias->getCommonPointer()->fn_flags;
        self::assertSame(Core::ZEND_ACC_PRIVATE, $flags & Core::ZEND_ACC_PRIVATE);
        self::assertSame(0, $flags & Core::ZEND_ACC_CHANGED, 'no ancestor declares the alias name: not a redeclaration');
        self::assertSame(0, $flags & Core::ZEND_ACC_HEAP_RT_CACHE, 'a cache shared by three tables is never heap-flagged');
    }

    public function testSubclassDeclaringTheAliasNameKeepsItAndGetsTheChangedFlag(): void
    {
        // Linked now: Mid declares a private mOriginal() of its own, Leaf2 inherited that
        // bucket (the same pointer) at link time
        self::assertSame('base2', (new OverridingLeaf2())->m());
        self::assertSame('mid-private', (new OverridingLeaf2())->ownAlias());
        $midMethod  = (new ReflectionClass(OverridingMid::class))->getMethod('mOriginal');
        $midAddress = $midMethod->getAddress();
        self::assertSame($midAddress, (new ReflectionClass(OverridingLeaf2::class))->getMethod('mOriginal')->getAddress());
        self::assertSame(0, $midMethod->getCommonPointer()->fn_flags & Core::ZEND_ACC_CHANGED, 'nothing to redeclare yet');
        $class = new ReflectionClass(OverridingBase2::class);
        $donor = (new ReflectionClass(OverridingDonor2::class))->getMethod('m');

        if ($class->isImmutable()) {
            // The CHANGED bit cannot be written into Mid's shared-memory entry either
            try {
                $class->getMethod('m')->redefine($donor, preserveAs: 'mOriginal');
                self::fail('alias into a shared class with a redeclaring subclass linked before not refused');
            } catch (SharedMemoryException $exception) {
                self::assertMatchesRegularExpression('/subclass .*Overriding(Mid|Leaf2) was linked against the shared entry/', $exception->getMessage());
            }
            self::assertTrue((new ReflectionClass(OverridingBase2::class))->isImmutable(), 'refused before anything was copied out');
            self::assertFalse((new \ReflectionClass(OverridingBase2::class))->hasMethod('mOriginal'));
            $midFlags = (new ReflectionClass(OverridingMid::class))->getMethod('mOriginal')->getCommonPointer()->fn_flags;
            self::assertSame(0, $midFlags & Core::ZEND_ACC_CHANGED, 'nothing was marked');
            self::assertSame('base2', (new OverridingLeaf2())->m(), 'nothing was swapped');

            return;
        }

        $class->getMethod('m')->redefine($donor, preserveAs: 'mOriginal');

        // The base body, private alias and all, runs on Mid and Leaf2 receivers: with the
        // CHANGED bit on Mid's own method, zend_std_get_method falls back to
        // zend_get_parent_private_method() and finds the base's alias instead of refusing
        // the call to Mid's private method from the base's scope
        self::assertSame('[base2]', (new OverridingBase2())->m());
        self::assertSame('[base2]', (new OverridingMid())->m(), 'the base scope resolves the base alias on a Mid receiver');
        self::assertSame('[base2]', (new OverridingLeaf2())->m());
        self::assertSame('mid-private', (new OverridingMid())->ownAlias(), 'Mid keeps its own private method for its own scope');
        self::assertSame('mid-private', (new OverridingLeaf2())->ownAlias());

        // Mid's method stayed Mid's own entry (not replaced by the alias), marked as the
        // engine marks a redeclared parent-private method, and Leaf2 still dispatches it
        $midMethod = (new ReflectionClass(OverridingMid::class))->getMethod('mOriginal');
        self::assertSame($midAddress, $midMethod->getAddress(), 'the own entry was kept in place');
        self::assertSame(OverridingMid::class, (new \ReflectionMethod(OverridingMid::class, 'mOriginal'))->getDeclaringClass()->getName());
        self::assertSame(OverridingMid::class, (new \ReflectionMethod(OverridingLeaf2::class, 'mOriginal'))->getDeclaringClass()->getName());
        self::assertSame(Core::ZEND_ACC_CHANGED, $midMethod->getCommonPointer()->fn_flags & Core::ZEND_ACC_CHANGED, 'redeclaration of the parent-private alias');
        self::assertSame(Core::ZEND_ACC_PRIVATE, $midMethod->getCommonPointer()->fn_flags & Core::ZEND_ACC_PRIVATE);
        self::assertSame($midAddress, (new ReflectionClass(OverridingLeaf2::class))->getMethod('mOriginal')->getAddress(), 'Leaf2 kept the inherited Mid bucket');

        // The base's alias is published in the base table only: the nearer declaration wins
        $alias = (new ReflectionClass(OverridingBase2::class))->getMethod('mOriginal');
        self::assertSame([OverridingBase2::class], self::holderNames($alias));
        self::assertSame(OverridingBase2::class, (new \ReflectionMethod(OverridingBase2::class, 'mOriginal'))->getDeclaringClass()->getName());
        self::assertSame(0, $alias->getCommonPointer()->fn_flags & Core::ZEND_ACC_CHANGED, 'no ancestor of the base declares the name');
    }

    public function testSubclassesLinkedAfterInheritTheAlias(): void
    {
        self::assertFalse(class_exists(OverridingLateSub::class, false), 'the subclasses are not linked yet');
        $class = new ReflectionClass(OverridingLateBase::class);
        $class->getMethod('m')->redefine((new ReflectionClass(OverridingLateDonor::class))->getMethod('m'), preserveAs: 'mOriginal');
        self::assertSame('[late-base]', (new OverridingLateBase())->m());

        // Autoloaded now, i.e. linked against the class (or its writable copy) that already
        // publishes the alias: the engine copies the private alias into their tables itself
        self::assertSame('late:[late-base]', (new OverridingLateSub())->m());
        self::assertSame('late:[late-base]', (new OverridingLateLeaf())->m());

        $alias = (new ReflectionClass(OverridingLateBase::class))->getMethod('mOriginal');
        foreach ([OverridingLateSub::class, OverridingLateLeaf::class] as $subclass) {
            self::assertTrue((new \ReflectionClass($subclass))->hasMethod('mOriginal'), $subclass);
            $native = new \ReflectionMethod($subclass, 'mOriginal');
            self::assertSame(OverridingLateBase::class, $native->getDeclaringClass()->getName(), $subclass);
            self::assertTrue($native->isPrivate(), $subclass);
            // zend_duplicate_function hands the child table the parent's pointer
            self::assertSame($alias->getAddress(), (new ReflectionClass($subclass))->getMethod('mOriginal')->getAddress(), $subclass);
        }
        self::assertSame(
            [OverridingLateBase::class, OverridingLateLeaf::class, OverridingLateSub::class],
            self::holderNames($alias),
        );
        // The alias took exactly one share for its own bucket, so the link-time shares of
        // the subclasses add up to one per publishing table (a shared-memory body, as the
        // copied-out class of the opcache runner keeps, is immortal and carries no counter)
        $refCountPointer = $alias->getOpArrayPointer()->refcount;
        if ($refCountPointer !== null) {
            self::assertSame(3, $refCountPointer[0]);
        }
    }

    public function testSubclassDeclaringTheAliasNameLinkedAfterIsMarkedChangedByTheEngine(): void
    {
        // The engine reference for the "linked before" redeclaration: a subclass declaring
        // a private method of the alias name and linking AFTER the alias exists must see a
        // regular parent-private method, which do_inheritance_check_on_method marks CHANGED
        self::assertFalse(class_exists(OverridingLateMid::class, false), 'the subclass is not linked yet');
        $class = new ReflectionClass(OverridingLateBase2::class);
        $class->getMethod('m')->redefine((new ReflectionClass(OverridingLateDonor2::class))->getMethod('m'), preserveAs: 'mOriginal');

        self::assertSame('[late-base2]', (new OverridingLateBase2())->m());
        self::assertSame('[late-base2]', (new OverridingLateMid())->m(), 'the base scope resolves the base alias on a Mid receiver');
        self::assertSame('late-mid-private', (new OverridingLateMid())->ownAlias(), 'Mid keeps its own private method for its own scope');

        $midMethod = (new ReflectionClass(OverridingLateMid::class))->getMethod('mOriginal');
        self::assertSame(OverridingLateMid::class, $midMethod->getDeclaringClass()->getName());
        self::assertSame(Core::ZEND_ACC_CHANGED, $midMethod->getCommonPointer()->fn_flags & Core::ZEND_ACC_CHANGED, 'the engine marked the redeclaration at link time');
        $alias = (new ReflectionClass(OverridingLateBase2::class))->getMethod('mOriginal');
        self::assertSame([OverridingLateBase2::class], self::holderNames($alias), 'the nearer declaration wins in the subclass table');
        self::assertSame(0, $alias->getCommonPointer()->fn_flags & Core::ZEND_ACC_CHANGED);
    }

    /**
     * Sorted names of the classes whose method tables publish the entry structure
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
}
