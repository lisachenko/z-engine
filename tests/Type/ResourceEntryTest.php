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
use PHPUnit\Framework\Attributes\Group;
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

        preg_match('/Resource id #(\d+)/', (string) $this->file, $matches);
        $this->assertSame((int) $matches[1], $refResource->getHandle());
    }

    #[Group('internal')]
    public function testSetHandle(): void
    {
        $refResource = new ResourceEntry($this->file);

        // The handle must be restored before tearDown: while it is aliased, closing the
        // stream would delete whichever unrelated resource currently owns list entry 1
        $originalHandle = $refResource->getHandle();
        try {
            $refResource->setHandle(1);
            $this->assertSame(1, $refResource->getHandle());
        } finally {
            $refResource->setHandle($originalHandle);
        }
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

    #[Group('internal')]
    public function testSetType()
    {
        $refResource = new ResourceEntry($this->file);

        $originalType = $refResource->getType();
        try {
            // persistent_stream has type=3
            $refResource->setType(3);
            $this->assertSame(3, $refResource->getType());
            ob_start();
            var_dump($this->file);
            $value = ob_get_clean();

            preg_match('/resource\(\d+\) of type \(([^)]+)\)/', $value, $matches);
            $this->assertSame('persistent stream', $matches[1]);
        } finally {
            // Restore before tearDown so fclose() treats the stream as a regular one again
            $refResource->setType($originalType);
        }
    }
}
