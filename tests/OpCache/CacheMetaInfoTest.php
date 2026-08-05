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
use ZEngine\Core;

final class CacheMetaInfoTest extends TestCase
{
    use FileCacheFixture;

    protected function tearDown(): void
    {
        self::removeCacheDir();
    }

    public function testByteSizeIsTheCompilerStructSize(): void
    {
        self::assertSame(Core::sizeof(Core::type('zend_file_cache_metainfo')), CacheMetaInfo::byteSize());
        // magic[8] + system_id[32] + 3 size_t + time_t + uint32_t, padded to 8
        self::assertSame(80, CacheMetaInfo::byteSize());
    }

    public function testParsesARealHeaderProducedByOpcache(): void
    {
        $binPath = self::compileFixture();
        $bytes   = (string) file_get_contents($binPath);
        $meta    = CacheMetaInfo::parse($bytes, $binPath);

        self::assertTrue($meta->systemId()->matchesCurrentBuild());
        self::assertGreaterThan(0, $meta->memSize());
        self::assertGreaterThanOrEqual(0, $meta->strSize());
        self::assertLessThan($meta->memSize(), $meta->scriptOffset());
        self::assertSame(filemtime(self::fixturePath()), $meta->timestamp());

        $payload = substr($bytes, CacheMetaInfo::byteSize());
        self::assertSame($meta->memSize() + $meta->strSize(), strlen($payload));
        self::assertSame($meta->checksum(), CacheMetaInfo::checksumOf($payload));
    }

    public function testBinaryRoundTripIsByteIdentical(): void
    {
        $binPath = self::compileFixture();
        $header  = substr((string) file_get_contents($binPath), 0, CacheMetaInfo::byteSize());

        self::assertSame($header, CacheMetaInfo::parse($header, $binPath)->toBinary());
    }

    public function testWithTimestampAndChecksumProduceModifiedHeaders(): void
    {
        $binPath = self::compileFixture();
        $meta    = CacheMetaInfo::parse((string) file_get_contents($binPath), $binPath);

        $modified = $meta->withTimestamp(123456789)->withChecksum(0xDEADBEEF);
        self::assertSame(123456789, $modified->timestamp());
        self::assertSame(0xDEADBEEF, $modified->checksum());
        // Unrelated fields survive the rewrite
        self::assertSame($meta->memSize(), $modified->memSize());
        self::assertSame($meta->scriptOffset(), $modified->scriptOffset());
        self::assertTrue($meta->systemId()->equals($modified->systemId()));
        // The original is immutable
        self::assertNotSame($meta->timestamp(), $modified->timestamp());
    }

    public function testRejectsForeignMagic(): void
    {
        $this->expectException(OpCacheException::class);
        $this->expectExceptionMessage('missing OPCACHE magic');
        CacheMetaInfo::parse(str_pad('NOTCACHE', CacheMetaInfo::byteSize(), "\0"), 'corrupt.bin');
    }

    public function testRejectsTruncatedHeader(): void
    {
        $this->expectException(OpCacheException::class);
        $this->expectExceptionMessage('truncated');
        CacheMetaInfo::parse('OPCACHE', 'short.bin');
    }
}
