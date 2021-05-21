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

use FFI\CData;
use PHPUnit\Framework\TestCase;

class ResourceEntryTest extends TestCase
{
    private $file;

    protected function setUp(): void
    {
        $this->file = fopen(__FILE__, 'r');
    }

    protected function tearDown(): void
    {
        fclose($this->file);
    }

    public function testGetHandle(): void
    {
        $refResource = new ResourceEntry($this->file);

        preg_match('/Resource id #(\d+)/', (string)$this->file, $matches);
        $this->assertSame((int)$matches[1], $refResource->getHandle());
        $refResource->setHandle(1);
        $this->assertSame(1, $refResource->getHandle());
    }

    /**
     * @group internal
     */
    public function testSetHandle(): void
    {
        $refResource = new ResourceEntry($this->file);

        $refResource->setHandle(1);
        $this->assertSame(1, $refResource->getHandle());
    }

    public function testGetRawData()
    {
        $refResource = new ResourceEntry($this->file);
        $rawData     = $refResource->getRawData();
        $this->assertInstanceOf(CData::class, $rawData);
    }

    public function testGetType()
    {
        $refResource = new ResourceEntry($this->file);

        // stream resource type has an id=2
        $this->assertSame(2, $refResource->getType());
    }

    /**
     * @group internal
     */
    public function testSetType()
    {
        $refResource = new ResourceEntry($this->file);

        // persistent_stream has type=3
        $refResource->setType(3);
        $this->assertSame(3, $refResource->getType());
        ob_start();
        var_dump($this->file);
        $value = ob_get_clean();

        preg_match('/resource\(\d+\) of type \(([^)]+)\)/', $value, $matches);
        $this->assertSame('persistent stream', $matches[1]);
    }
}
