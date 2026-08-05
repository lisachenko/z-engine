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

final class SystemIdTest extends TestCase
{
    public function testCurrentIsThirtyTwoHexCharacters(): void
    {
        $current = SystemId::current();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $current->toHex());
        self::assertTrue($current->matchesCurrentBuild());
    }

    public function testBinaryRoundTripAndEquality(): void
    {
        $current = SystemId::current();
        $parsed  = SystemId::fromBinary($current->toHex());
        self::assertTrue($parsed->equals($current));
        self::assertSame($current->toHex(), $parsed->toHex());

        $foreign = SystemId::fromBinary(str_repeat('ab', 16));
        self::assertFalse($foreign->equals($current));
        self::assertFalse($foreign->matchesCurrentBuild());
    }

    public function testRejectsMalformedIdentifier(): void
    {
        $this->expectException(OpCacheException::class);
        $this->expectExceptionMessage('Invalid system id');
        SystemId::fromBinary('not-a-system-id');
    }
}
