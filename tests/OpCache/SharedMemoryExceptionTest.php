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

namespace ZEngine\OpCache;

use PHPUnit\Framework\TestCase;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionFunction;

final class SharedMemoryExceptionTest extends TestCase
{
    public function testExceptionLivesInTheOpCacheNamespaceAndStaysCatchable(): void
    {
        // Existing catch (\ReflectionException) blocks keep working after the move
        self::assertInstanceOf(
            \ReflectionException::class,
            SharedMemoryException::immutableMethodTable(),
        );
    }

    public function testRuntimeDeclaredCodeIsNotReportedAsImmutable(): void
    {
        // Detection is on the owning wrappers; code declared in this process
        // (never opcache-shared) is never immutable
        self::assertFalse((new ReflectionClass(self::class))->isImmutable());
        self::assertFalse((new ReflectionFunction('strlen'))->isImmutable());
    }
}
