<?php
/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\Type;


use PHPUnit\Framework\TestCase;

class ObjectEntryTest extends TestCase
{
    private $instance;

    protected function setUp(): void
    {
        $this->instance = new \RuntimeException('Test');
    }

    public function testGetClass(): void
    {
        $class = (new ObjectEntry($this->instance))->getClass();
        $this->assertSame(\RuntimeException::class, $class->getName());
    }

    public function testSetClass(): void
    {
        $objectEntry = new ObjectEntry($this->instance);
        $objectEntry->setClass(\Exception::class);

        $className = get_class($this->instance);

        $this->assertSame(\Exception::class, $className);
    }

    public function testGetHandle(): void
    {
        $objectEntry  = new ObjectEntry($this->instance);
        $objectHandle = spl_object_id($this->instance);

        $this->assertSame($objectHandle, $objectEntry->getHandle());
    }

    /**
     * @depends testGetHandle
     */
    public function testSetHandle(): void
    {
        $objectEntry    = new ObjectEntry($this->instance);
        $originalHandle = spl_object_id($this->instance);
        $entryHandle    = spl_object_id($objectEntry);
        // We just update a handle for internal object to be the same as $objectEntry itself
        $objectEntry->setHandle($entryHandle);

        $this->assertSame(spl_object_id($objectEntry), spl_object_id($this->instance));
        $this->assertNotSame($objectEntry, $this->instance);

        // This is required to prevent a ZEND_ASSERT(EG(objects_store).object_buckets != NULL) during shutdown
        $objectEntry->setHandle($originalHandle);
    }
}
