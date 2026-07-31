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

/**
 * Ownership semantics of ReflectionValue: owning constructor, borrowed factories,
 * idempotent release and use-after-release guards
 */
final class ReflectionValueReleaseTest extends TestCase
{
    public function testConstructorTakesExactlyOneReference(): void
    {
        $payload = str_repeat('z', 32) . random_int(100, 999);

        $first    = new ReflectionValue($payload);
        $baseline = $first->getReferenceCount();

        $second = new ReflectionValue($payload);
        $this->assertSame($baseline + 1, $first->getReferenceCount());

        $second->release();
        $this->assertSame($baseline, $first->getReferenceCount());
    }

    public function testReleaseIsIdempotent(): void
    {
        $value = new ReflectionValue('some value ' . random_int(100, 999));
        $value->release();
        $value->release();

        $this->assertTrue($value->isReleased());
    }

    public function testAccessAfterReleaseThrows(): void
    {
        $value = new ReflectionValue('released value ' . random_int(100, 999));
        $value->release();

        $this->expectException(\LogicException::class);
        $value->getRawValue();
    }

    public function testCopyAfterReleaseThrows(): void
    {
        $value = new ReflectionValue('released value ' . random_int(100, 999));
        $other = new ReflectionValue('destination');
        $value->release();

        $this->expectException(\LogicException::class);
        $value->copy($other->getRawValue());
    }

    public function testBorrowedEntriesTreatReleaseAsNoOp(): void
    {
        $owner    = new ReflectionValue(str_repeat('b', 24));
        $borrowed = ReflectionValue::fromValueEntry($owner->getRawValue());

        $borrowed->release();

        $this->assertFalse($borrowed->isReleased());
        // The borrowed wrapper stays fully usable
        $borrowed->getNativeValue($result);
        $this->assertSame(str_repeat('b', 24), $result);
    }

    public function testDestructorReleasesOwnedReference(): void
    {
        $payload  = str_repeat('d', 24) . random_int(100, 999);
        $keeper   = new ReflectionValue($payload);
        $baseline = $keeper->getReferenceCount();

        $temporary = new ReflectionValue($payload);
        $this->assertSame($baseline + 1, $keeper->getReferenceCount());
        unset($temporary);

        $this->assertSame($baseline, $keeper->getReferenceCount());
    }

    public function testGetGCThrowsForNonRefcountedValues(): void
    {
        $value = new ReflectionValue(42);

        $this->expectException(\LogicException::class);
        $value->getReferenceCount();
    }

    public function testSetNativeValueReleasesPreviousContent(): void
    {
        $payload  = str_repeat('p', 24) . random_int(100, 999);
        $keeper   = new ReflectionValue($payload);
        $baseline = $keeper->getReferenceCount();

        $variable = $payload;      // +1 reference held by the variable
        $this->assertSame($baseline + 1, $keeper->getReferenceCount());

        $target = new ReflectionValue($variable); // +1 owned by the wrapper
        $target->setNativeValue('replacement');   // the wrapper's reference is released

        $this->assertSame($baseline + 1, $keeper->getReferenceCount());
    }
}
