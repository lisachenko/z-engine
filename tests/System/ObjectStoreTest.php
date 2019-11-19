<?php
/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */
declare(strict_types=1);

namespace ZEngine\System;


use LogicException;
use PHPUnit\Framework\TestCase;
use ZEngine\Core;
use ZEngine\Type\ObjectEntry;

class ObjectStoreTest extends TestCase
{
    private ObjectStore $objectStore;

    protected function setUp(): void
    {
        $this->objectStore = Core::$executor->objectStore;
    }

    public function testOffsetUnsetThrowsAnException(): void
    {
        $this->expectException(LogicException::class);
        $id = spl_object_id($this);
        unset($this->objectStore[$id]);
    }

    public function testOffsetSetThrowsAnException(): void
    {
        $this->expectException(LogicException::class);
        $id = spl_object_id($this);
        $this->objectStore[$id] = new ObjectEntry($this);
    }

    public function testOffsetGetReturnsObjects(): void
    {
        $id          = spl_object_id($this);
        $objectEntry = $this->objectStore->offsetGet($id);
        $this->assertInstanceOf(ObjectEntry::class, $objectEntry);
        $this->assertSame($this, $objectEntry->getNativeValue());

        // Now let's create new object and check that it's still accessible
        $newObject   = new \stdClass();
        $id          = spl_object_id($newObject);
        $objectEntry = $this->objectStore->offsetGet($id);
        $this->assertSame($newObject, $objectEntry->getNativeValue());
    }

    public function testOffsetExists(): void
    {
        $id = spl_object_id($this);
        $this->assertTrue($this->objectStore->offsetExists($id));
        $this->assertTrue(isset($this->objectStore[$id]));
    }

    public function testCount(): void
    {
        $currentCount = count($this->objectStore);
        $this->assertGreaterThan(0, $currentCount);
        // We cannot predict the size of objectStore, because it can reuse previously deleted slots
    }

    public function testNextHandle(): void
    {
        // We can predict what will be the next handle of object
        $expectedHandle = $this->objectStore->nextHandle();
        $object         = new \stdClass();
        $objectHandle   = spl_object_id($object);
        $nextHandle     = $this->objectStore->nextHandle();

        $this->assertSame($expectedHandle, $objectHandle);
        $this->assertNotSame($expectedHandle, $nextHandle);
    }
}
