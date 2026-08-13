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
        // Existing catch (\ReflectionException) blocks keep working after the move.
        // Statically always true is exactly the guarantee being pinned: the test exists so a
        // future reparenting of the exception fails here instead of in a consumer catch block
        // @phpstan-ignore staticMethod.alreadyNarrowedType (pins the inheritance the API promises)
        self::assertInstanceOf(
            \ReflectionException::class,
            SharedMemoryException::methodMissingAfterCopyOut('Some\Shared', 'method'),
        );
    }

    public function testCopyOutFailureCarriesTheRefusalReason(): void
    {
        $reason    = new \RuntimeException('classes with property hooks are not supported');
        $exception = SharedMemoryException::classCopyOutFailed('Some\Shared', $reason);

        self::assertSame($reason, $exception->getPrevious());
        self::assertStringContainsString('Some\Shared', $exception->getMessage());
        self::assertStringContainsString('property hooks', $exception->getMessage());
    }

    public function testMutationFailureNamesTheOperationAndTheReason(): void
    {
        $exception = SharedMemoryException::immutableClassMutation(
            'add a method',
            SharedMemoryException::classCopyOutFailed('Some\Shared', new \RuntimeException('it is an enum')),
        );

        self::assertStringContainsString('add a method', $exception->getMessage());
        self::assertStringContainsString('it is an enum', $exception->getMessage());
        self::assertInstanceOf(SharedMemoryException::class, $exception->getPrevious());
    }

    public function testRuntimeDeclaredCodeIsNotReportedAsImmutable(): void
    {
        // Detection is on the owning wrappers; code declared in this process
        // (never opcache-shared) is never immutable
        self::assertFalse((new ReflectionClass(self::class))->isImmutable());
        self::assertFalse((new ReflectionFunction('strlen'))->isImmutable());
    }
}
