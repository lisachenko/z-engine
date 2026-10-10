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
use ZEngine\Stub\Alias\ChangedEarlyDonor;
use ZEngine\Stub\Alias\ChangedEarlyLeaf;
use ZEngine\Stub\Alias\ChangedEarlyMid;
use ZEngine\Stub\Alias\ChangedEarlySub;
use ZEngine\Stub\Alias\ChangedLeaf;
use ZEngine\Stub\Alias\ChangedLeafDonor;
use ZEngine\Stub\Alias\ChangedLeafSub;
use ZEngine\Stub\Alias\ChangedMid;
use ZEngine\Stub\Alias\ChangedPlainBase;
use ZEngine\Stub\Alias\ChangedPlainChild;
use ZEngine\Stub\Alias\ChangedPlainDonor;
use ZEngine\Stub\Alias\ChangedSecretBase;
use ZEngine\Stub\Alias\ChangedSecretChild;
use ZEngine\Stub\Alias\ChangedSecretDonor;

/**
 * The ZEND_ACC_CHANGED bit of the override addMethod() publishes over an inherited method
 *
 * The engine marks a method redeclaring a parent-private method ZEND_ACC_CHANGED and hands
 * the bit down to every override of a method carrying it (do_inheritance_check_on_method:
 * a parent flagged PRIVATE or CHANGED flags the child CHANGED). zend_std_get_method reads
 * the bit when the calling scope is not the scope of the method it found: it looks the name
 * up in the calling scope's own table (zend_get_parent_private_method) and dispatches that
 * private method instead, which is how `$this->m()` in an ancestor keeps reaching the
 * ancestor's own private m() on an instance of a class whose public m() shadows it. The
 * override MethodPublisher::publishOverride() builds for addMethod() set the bit only when
 * the inherited entry itself was private: over an inherited entry that merely CARRIED the
 * bit the override had it cleared, and the ancestor's own code was routed to the donor body.
 *
 * Observing the dispatch takes care, twice. The `$this->m()` call site caches (class entry,
 * resolved method) monomorphically in the run-time cache of the ancestor's method, so a
 * call warmed before the publication keeps answering from the slot afterwards as long as
 * the class entry is unchanged - hiding a wrong bit: every "after" dispatch asserted here
 * is preceded by a call through an instance of another class, which evicts the slot and
 * makes the asserted call a full lookup. And a `new X()` literal caches the class entry it
 * resolved in the run-time cache slot of its class-name literal, one slot per literal that
 * the optimizer shares between all `new X()` of a method, so after the copy-out of an
 * opcache-shared class the literal keeps instantiating the shared-memory class: the classes
 * a publication targets are instantiated through a run-time class lookup (instance()). The
 * private bodies name their receiver so that the optimizer cannot inline the call at cache
 * time (a constant-returning private method is).
 *
 * Every scenario owns its stub classes (tests/Stub/Alias/Changed*): a publication changes
 * the class for the rest of the process. Subclasses are autoloaded AFTER the publication
 * except where "linked before" is the subject, which the opcache-active runner (every stub
 * an immutable shared-memory class) pins as the refusal instead.
 */
class AddMethodChangedFlagTest extends TestCase
{
    public function testOverrideOfAChangedInheritedMethodKeepsTheRootPrivateDispatch(): void
    {
        $midMethod  = (new ReflectionClass(ChangedMid::class))->getMethod('m');
        $leafBefore = (new ReflectionClass(ChangedLeaf::class))->getMethod('m');
        // The premise: Mid::m redeclares the root's private m(), so the engine flagged it
        // CHANGED at link time, and the leaf's table inherits that very entry
        self::assertSame(Core::ZEND_ACC_CHANGED, self::flagsOf($midMethod) & Core::ZEND_ACC_CHANGED);
        self::assertSame($midMethod->getAddress(), $leafBefore->getAddress());
        self::assertSame('root@' . ChangedLeaf::class, self::instance(ChangedLeaf::class)->viaRoot(), 'the root resolves its own private m() on a leaf instance');
        self::assertSame('mid', self::instance(ChangedLeaf::class)->m());

        $donor     = (new ReflectionClass(ChangedLeafDonor::class))->getMethod('m');
        $published = (new ReflectionClass(ChangedLeaf::class))->addMethod('m', $donor);

        // The bit the engine sets on a compiled override of a CHANGED method, on an own
        // entry of the leaf whose prototype is the overridden Mid::m (which has none itself)
        self::assertSame(Core::ZEND_ACC_CHANGED, self::flagsOf($published) & Core::ZEND_ACC_CHANGED);
        self::assertSame(ChangedLeaf::class, $published->getDeclaringClass()->getName());
        self::assertNotSame($midMethod->getAddress(), $published->getAddress());
        self::assertSame($published->getAddress(), (new ReflectionClass(ChangedLeaf::class))->getMethod('m')->getAddress());
        self::assertSame($midMethod->getAddress(), $published->getPrototype()?->getAddress());
        $native = new \ReflectionMethod(ChangedLeaf::class, 'm');
        self::assertSame(ChangedLeaf::class, $native->getDeclaringClass()->getName());
        self::assertSame(ChangedMid::class, $native->getPrototype()->getDeclaringClass()->getName());
        self::assertTrue($native->isPublic());

        // Mid is untouched - and the call evicts the leaf from the `$this->m()` slot of
        // viaRoot(), so the leaf dispatch below is a full lookup reading the published flags
        self::assertSame('root@' . ChangedMid::class, (new ChangedMid())->viaRoot());
        self::assertSame('mid', (new ChangedMid())->m());
        self::assertSame('root@' . ChangedLeaf::class, self::instance(ChangedLeaf::class)->viaRoot(), 'the root still resolves its own private m() on a leaf instance');
        self::assertSame('donor', self::instance(ChangedLeaf::class)->m(), 'every other caller reaches the donor body');

        // Receivers alternating on the same call site: every call is a full lookup
        for ($i = 0; $i < 200; $i++) {
            self::assertSame('root@' . ChangedLeaf::class, self::instance(ChangedLeaf::class)->viaRoot());
            self::assertSame('root@' . ChangedMid::class, (new ChangedMid())->viaRoot());
        }

        // Autoloaded now, i.e. linked against the leaf with the override: the subclass
        // inherits the published entry itself (do_inherit_method), bit included
        $subMethod = (new ReflectionClass(ChangedLeafSub::class))->getMethod('m');
        self::assertSame($published->getAddress(), $subMethod->getAddress());
        self::assertSame(Core::ZEND_ACC_CHANGED, self::flagsOf($subMethod) & Core::ZEND_ACC_CHANGED);
        self::assertSame('root@' . ChangedLeafSub::class, self::instance(ChangedLeafSub::class)->viaRoot());
        self::assertSame('donor', self::instance(ChangedLeafSub::class)->m());
    }

    public function testOverrideIsSharedIntoASubclassLinkedBeforeWithTheChangedFlag(): void
    {
        // The whole chain is linked now: Sub's table inherits Mid's CHANGED entry through Leaf
        $midMethod = (new ReflectionClass(ChangedEarlyMid::class))->getMethod('m');
        self::assertSame($midMethod->getAddress(), (new ReflectionClass(ChangedEarlySub::class))->getMethod('m')->getAddress());
        self::assertSame('root@' . ChangedEarlySub::class, self::instance(ChangedEarlySub::class)->viaRoot());
        self::assertSame('mid', self::instance(ChangedEarlySub::class)->m());
        $leaf  = new ReflectionClass(ChangedEarlyLeaf::class);
        $donor = (new ReflectionClass(ChangedEarlyDonor::class))->getMethod('m');

        if ($leaf->isImmutable()) {
            // Refused before the copy-out: the subclass linked against the shared entry would
            // keep dispatching Mid's entry while the leaf copy carried the override
            try {
                $leaf->addMethod('m', $donor);
                self::fail('override on a shared class with a subclass linked before not refused');
            } catch (SharedMemoryException $exception) {
                self::assertMatchesRegularExpression('/subclass .*ChangedEarlySub was linked against the shared entry/', $exception->getMessage());
            }
            self::assertTrue((new ReflectionClass(ChangedEarlyLeaf::class))->isImmutable(), 'refused before anything was copied out');
            self::assertSame(ChangedEarlyMid::class, (new \ReflectionMethod(ChangedEarlyLeaf::class, 'm'))->getDeclaringClass()->getName());
            self::assertSame('mid', self::instance(ChangedEarlySub::class)->m(), 'nothing was published');
            self::assertSame('root@' . ChangedEarlySub::class, self::instance(ChangedEarlySub::class)->viaRoot());

            return;
        }

        $published = $leaf->addMethod('m', $donor);

        // The subclass table now holds the published entry (shareIntoSubclass): the same
        // pointer, so the CHANGED bit travels with it
        $subMethod = (new ReflectionClass(ChangedEarlySub::class))->getMethod('m');
        self::assertSame($published->getAddress(), $subMethod->getAddress());
        self::assertSame(Core::ZEND_ACC_CHANGED, self::flagsOf($subMethod) & Core::ZEND_ACC_CHANGED);
        self::assertSame(ChangedEarlyLeaf::class, (new \ReflectionMethod(ChangedEarlySub::class, 'm'))->getDeclaringClass()->getName());

        // Evict the warmed slot through Mid; the subclass and leaf dispatches are full lookups
        self::assertSame('root@' . ChangedEarlyMid::class, (new ChangedEarlyMid())->viaRoot());
        self::assertSame('root@' . ChangedEarlySub::class, self::instance(ChangedEarlySub::class)->viaRoot(), 'the root resolves its own private m() on the subclass linked before');
        self::assertSame('donor', self::instance(ChangedEarlySub::class)->m());
        self::assertSame('root@' . ChangedEarlyLeaf::class, self::instance(ChangedEarlyLeaf::class)->viaRoot());
        self::assertSame('donor', self::instance(ChangedEarlyLeaf::class)->m());
    }

    public function testOverrideOfAParentPrivateMethodIsMarkedChangedAndLinksNoPrototype(): void
    {
        $baseMethod  = (new ReflectionClass(ChangedSecretBase::class))->getMethod('m');
        $childBefore = (new ReflectionClass(ChangedSecretChild::class))->getMethod('m');
        // The premise: a parent's private method is copied into the child's table at link time
        self::assertSame($baseMethod->getAddress(), $childBefore->getAddress());
        self::assertSame(Core::ZEND_ACC_PRIVATE, self::flagsOf($childBefore) & Core::ZEND_ACC_PRIVATE);
        self::assertTrue((new \ReflectionMethod(ChangedSecretChild::class, 'm'))->isPrivate());
        self::assertSame('secret@' . ChangedSecretChild::class, self::instance(ChangedSecretChild::class)->viaBase());

        $donor     = (new ReflectionClass(ChangedSecretDonor::class))->getMethod('m');
        $published = (new ReflectionClass(ChangedSecretChild::class))->addMethod('m', $donor);

        // A redeclared parent-private method: CHANGED, and no prototype - the engine does not
        // treat it as an override of the private method
        self::assertSame(Core::ZEND_ACC_CHANGED, self::flagsOf($published) & Core::ZEND_ACC_CHANGED);
        self::assertNull($published->getPrototype());
        $native = new \ReflectionMethod(ChangedSecretChild::class, 'm');
        self::assertSame(ChangedSecretChild::class, $native->getDeclaringClass()->getName());
        self::assertTrue($native->isPublic());
        self::assertFalse($native->hasPrototype());
        self::assertTrue((new \ReflectionMethod(ChangedSecretBase::class, 'm'))->isPrivate(), 'the base declaration is untouched');

        // Evict the warmed slot through the base; the child dispatch is a full lookup
        self::assertSame('secret@' . ChangedSecretBase::class, (new ChangedSecretBase())->viaBase());
        self::assertSame('secret@' . ChangedSecretChild::class, self::instance(ChangedSecretChild::class)->viaBase(), 'the base resolves its own private m() on a child instance');
        self::assertSame('donor', self::instance(ChangedSecretChild::class)->m(), 'the override is public to everyone else');
    }

    public function testPlainOverrideOfAPublicInheritedMethodCarriesNoChangedBit(): void
    {
        $baseMethod  = (new ReflectionClass(ChangedPlainBase::class))->getMethod('m');
        $donorMethod = (new ReflectionClass(ChangedPlainDonor::class))->getMethod('m');
        // The inherited entry carries no CHANGED bit; the donor does (it redeclares a private
        // method of its own parent), and the bit must not travel with the donor body
        self::assertSame(0, self::flagsOf($baseMethod) & Core::ZEND_ACC_CHANGED);
        self::assertSame(Core::ZEND_ACC_CHANGED, self::flagsOf($donorMethod) & Core::ZEND_ACC_CHANGED);
        self::assertSame($baseMethod->getAddress(), (new ReflectionClass(ChangedPlainChild::class))->getMethod('m')->getAddress());
        self::assertSame('base', (new ChangedPlainBase())->viaBase());

        $published = (new ReflectionClass(ChangedPlainChild::class))->addMethod('m', $donorMethod);

        self::assertSame(0, self::flagsOf($published) & Core::ZEND_ACC_CHANGED, 'the bit follows the inherited entry, not the donor');
        self::assertSame($baseMethod->getAddress(), $published->getPrototype()?->getAddress());
        $native = new \ReflectionMethod(ChangedPlainChild::class, 'm');
        self::assertSame(ChangedPlainChild::class, $native->getDeclaringClass()->getName());
        self::assertSame(ChangedPlainBase::class, $native->getPrototype()->getDeclaringClass()->getName());

        // Without the bit the base's own code dispatches virtually and reaches the override,
        // as it does for a compiled override of a public method
        self::assertSame('donor', self::instance(ChangedPlainChild::class)->viaBase());
        self::assertSame('donor', self::instance(ChangedPlainChild::class)->m());
        self::assertSame('base', (new ChangedPlainBase())->viaBase(), 'the base is untouched');
        self::assertSame('base', (new ChangedPlainBase())->m());
    }

    /**
     * Instantiates a class through a run-time class lookup: unlike a `new X()` literal,
     * whose class-name literal caches the class entry it resolved first, the lookup sees
     * the copy a shared-memory class was replaced with by a publication
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function instance(string $class): object
    {
        return new $class();
    }

    /**
     * The fn_flags of an entry, read through the typed common struct
     */
    private static function flagsOf(FunctionLikeInterface $entry): int
    {
        $flags = $entry->getCommonPointer()->fn_flags;
        assert(is_int($flags));

        return $flags;
    }
}
