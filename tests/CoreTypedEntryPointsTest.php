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

namespace ZEngine;

use PHPUnit\Framework\TestCase;
use ZEngine\Generated\zend_string;
use ZEngine\Generated\zval;

/**
 * Covers the typed entry points of Core: a generated struct stub class-string
 * (ZEngine\Generated\*) resolves to the same C type as the raw name string, with
 * cast()/pointerAtAddress() treating the stub form as a pointer cast. The stub
 * classes themselves are analysis-only and never loaded - the ::class constants
 * used here are plain strings at runtime.
 */
final class CoreTypedEntryPointsTest extends TestCase
{
    public function testStubClassResolvesToTheSameTypeAsTheRawName(): void
    {
        $this->assertSame(Core::sizeOfType('zval'), Core::sizeOfType(zval::class));
        $this->assertSame(Core::sizeOfType('zend_string'), Core::sizeOfType(zend_string::class));
    }

    public function testStubClassAllocationMatchesTheRawNameAllocation(): void
    {
        $entry = Core::new(zval::class);

        $this->assertSame(Core::sizeOfType('zval'), Core::sizeof($entry));
    }

    public function testStubClassCastIsAPointerCast(): void
    {
        $entry   = Core::new('zval');
        $viaName = Core::cast('zval *', Core::addr($entry));
        $viaStub = Core::cast(zval::class, Core::addr($entry));

        $this->assertSame(Core::addressOf($viaName), Core::addressOf($viaStub));
    }

    public function testStubClassPointerAtAddressIsAPointerCast(): void
    {
        $entry   = Core::new('zval');
        $address = Core::addressOf(Core::addr($entry));

        $this->assertSame($address, Core::addressOf(Core::pointerAtAddress(zval::class, $address)));
    }

    public function testFieldOffsetsAreReachableWithoutATypeHandle(): void
    {
        // The named replacement for type(...)->getStructFieldOffset(...): a consumer must be
        // able to ask where a field starts without a raw FFI\CType crossing the boundary
        $this->assertSame(
            Core::type('zend_string')->getStructFieldOffset('val'),
            Core::offsetOfField(zend_string::class, 'val'),
        );
        $this->assertSame(
            Core::offsetOfField('zend_string', 'val'),
            Core::offsetOfField(zend_string::class, 'val'),
        );

        // The first field of a struct sits at offset zero, the next ones behind it
        $this->assertSame(0, Core::offsetOfField(zend_string::class, 'gc'));
        $this->assertGreaterThan(0, Core::offsetOfField(zend_string::class, 'len'));
        $this->assertLessThan(Core::sizeOfType(zend_string::class), Core::offsetOfField(zend_string::class, 'len'));
    }

    public function testStubClassesAreNeverLoadedAtRuntime(): void
    {
        $this->assertFalse(class_exists(zval::class, false), 'stub classes must stay analysis-only');
    }

    public function testForeignClassNamesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Core::new(\ZEngine\Type\HashTable::class);
    }

    public function testNonCDataPointersAreRejected(): void
    {
        $this->expectException(\TypeError::class);
        Core::cast('zval *', new \stdClass());
    }
}
