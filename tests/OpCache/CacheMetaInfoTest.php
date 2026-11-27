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

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZEngine\Core;

#[Group('opcache')]
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
        $binPath      = self::compileFixture();
        $header       = substr((string) file_get_contents($binPath), 0, CacheMetaInfo::byteSize());
        $roundTripped = CacheMetaInfo::parse($header, $binPath)->toBinary();

        // Every byte the format defines - up to and including the checksum - survives the
        // round trip. The struct's trailing padding does NOT take part: opcache fills the
        // metainfo on the stack and writes it out without zeroing the padding, so those
        // bytes are whatever the engine's stack held (a debug build makes that visible).
        // toBinary() writes them as zeros, which no reader interprets.
        $definedBytes = Core::type('zend_file_cache_metainfo')->getStructFieldOffset('checksum')
            + Core::sizeof(Core::type('uint32_t'));

        self::assertSame(substr($header, 0, $definedBytes), substr($roundTripped, 0, $definedBytes));
        self::assertSame(CacheMetaInfo::byteSize(), strlen($roundTripped));
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

    /**
     * The with*() derivations clone-with a single key: the engine does not reject a misspelt
     * key (it only adds a dynamic property and deprecates), so every derivation is pinned here
     * to change exactly its own field, carry the other five over and leave the source intact.
     */
    public function testEachWithDerivationChangesExactlyItsOwnField(): void
    {
        $systemId = SystemId::fromBinary(str_repeat('a', SystemId::LENGTH));
        $original = CacheMetaInfo::forPayload(
            systemId: $systemId,
            memSize: 11,
            strSize: 22,
            scriptOffset: 33,
            timestamp: 44,
            checksum: 55,
        );
        $snapshot = static fn(CacheMetaInfo $meta): array => [
            'memSize'      => $meta->memSize,
            'strSize'      => $meta->strSize,
            'scriptOffset' => $meta->scriptOffset,
            'timestamp'    => $meta->timestamp,
            'checksum'     => $meta->checksum,
        ];
        $before = $snapshot($original);

        $derivations = [
            'timestamp' => $original->withTimestamp(1001),
            'checksum'  => $original->withChecksum(1002),
            'strSize'   => $original->withStrSize(1003),
        ];
        $newValues = ['timestamp' => 1001, 'checksum' => 1002, 'strSize' => 1003];

        foreach ($derivations as $field => $derived) {
            self::assertNotSame($original, $derived);
            self::assertSame([...$before, $field => $newValues[$field]], $snapshot($derived), $field);
            self::assertSame($systemId, $derived->systemId);
            // No stray dynamic property from a misspelt clone-with key
            self::assertSame(
                ['systemId', 'memSize', 'strSize', 'scriptOffset', 'timestamp', 'checksum'],
                array_keys(get_object_vars($derived)),
                $field,
            );
        }
        self::assertSame($before, $snapshot($original), 'the source header is immutable');
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
