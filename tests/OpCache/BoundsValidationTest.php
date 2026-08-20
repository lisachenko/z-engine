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

use FFI;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZEngine\Core;
use ZEngine\Generated\zend_script;

/**
 * Bounds-validation coverage (issue #123): a crafted or truncated binary whose
 * stored offsets, counts or element spans escape the declared buffer must be
 * refused loudly - never dereferenced. Every stored offset in a .bin is
 * attacker-controllable (system_id is a build fingerprint, adler32 is
 * forgeable), so an application that feeds an untrusted binary into
 * getReflection() must get an exception, not an out-of-bounds engine walk or a
 * crash. These tests corrupt a real payload's structural fields and assert the
 * loud refusal; they run in the debug container too, where an unguarded
 * out-of-bounds read segfaults the loudest.
 */
#[Group('opcache')]
#[Group('opcache-relocator')]
final class BoundsValidationTest extends TestCase
{
    use FileCacheFixture;

    protected function setUp(): void
    {
        if (!PayloadRelocator::isSupported()) {
            self::markTestSkipped(
                'The file-cache relocator supports 64-bit POSIX payloads only'
                . ' (Windows opcache support is an intentional non-goal, issue #119)',
            );
        }
    }

    protected function tearDown(): void
    {
        self::removeCacheDir();
    }

    public function testTruncatedBufferIsRefused(): void
    {
        [$payload, $meta] = $this->compiledPayload();
        // The header still claims the full memSize, but the buffer is short:
        // scriptOffset now points past the (shrunk) region
        $truncated = substr($payload, 0, intdiv(strlen($payload), 2));
        $buffer    = $this->bufferOf($truncated, strlen($payload));
        // Shrink the region the relocator believes it has to the real length
        $shortMeta = CacheMetaInfo::forPayload(
            systemId: SystemId::current(),
            memSize: strlen($truncated),
            strSize: 0,
            scriptOffset: $meta->scriptOffset(),
            timestamp: 0,
            checksum: 0,
        );

        $this->expectException(OpCacheException::class);
        $this->expectExceptionMessageMatches('/Malformed opcache payload/');
        (new PayloadRelocator($buffer, $shortMeta))->relocate();
    }

    public function testScriptOffsetPastRegionIsRefused(): void
    {
        [$payload, $meta] = $this->compiledPayload();
        $buffer           = $this->bufferOf($payload);
        $hostileMeta      = CacheMetaInfo::forPayload(
            systemId: SystemId::current(),
            memSize: $meta->memSize(),
            strSize: $meta->strSize(),
            scriptOffset: $meta->memSize() + 4096, // well past the region
            timestamp: 0,
            checksum: 0,
        );

        $this->expectException(OpCacheException::class);
        $this->expectExceptionMessageMatches('/scriptOffset|Malformed opcache payload/');
        (new PayloadRelocator($buffer, $hostileMeta))->relocate();
    }

    public function testHostileScriptPointerFieldIsRefused(): void
    {
        [$payload, $meta] = $this->compiledPayload();
        $buffer           = $this->bufferOf($payload);
        $base             = Core::addressOf(Core::addr($buffer));

        // Corrupt the script's filename offset to a wild value past the region.
        // zend_script is the first member of zend_persistent_script, so the
        // script offset is a zend_script* - single-hop to keep the field typed.
        $script = Core::pointerAtAddress(zend_script::class, $base + $meta->scriptOffset());
        // The compiled fixture always carries a filename block
        \assert($script->filename !== null);
        // FFI::addr must stay inline on the pointer-field access to yield the filename SLOT address
        // @phpstan-ignore argument.type (FFI::addr of a zend_string* pointer field)
        $filenameAt                                                                 = Core::addressOf(FFI::addr($script->filename));
        Core::cast('uintptr_t *', Core::pointerAtAddress('void *', $filenameAt))[0] = $meta->memSize() + 0x4000;

        $this->expectException(OpCacheException::class);
        $this->expectExceptionMessageMatches('/string field filename|Malformed opcache payload/');
        (new PayloadRelocator($buffer, $meta))->relocate();
    }

    public function testHostileHashCountIsRefused(): void
    {
        [$payload, $meta] = $this->compiledPayload();
        $buffer           = $this->bufferOf($payload);
        $base             = Core::addressOf(Core::addr($buffer));

        // Blow up the function table's nNumUsed so the bucket walk would spill
        $script                  = Core::pointerAtAddress(zend_script::class, $base + $meta->scriptOffset());
        $functionTableAt         = Core::addressOf(Core::addr($script->function_table));
        $functionTable           = Core::pointerAtAddress('HashTable *', $functionTableAt);
        $functionTable->nNumUsed = 0x7fffffff;

        $this->expectException(OpCacheException::class);
        $this->expectExceptionMessageMatches('/count|span|Malformed opcache payload/');
        (new PayloadRelocator($buffer, $meta))->relocate();
    }

    public function testHostileInternedStringOffsetIsRefused(): void
    {
        [$payload, $meta] = $this->compiledPayload();
        $buffer           = $this->bufferOf($payload);
        $base             = Core::addressOf(Core::addr($buffer));

        // Tag the script filename as an interned reference far past the (empty)
        // string section - a plausible-looking but out-of-range interned offset
        $script = Core::pointerAtAddress(zend_script::class, $base + $meta->scriptOffset());
        // The compiled fixture always carries a filename block
        \assert($script->filename !== null);
        // FFI::addr must stay inline on the pointer-field access to yield the filename SLOT address
        // @phpstan-ignore argument.type (FFI::addr of a zend_string* pointer field)
        $filenameAt                                                                 = Core::addressOf(FFI::addr($script->filename));
        Core::cast('uintptr_t *', Core::pointerAtAddress('void *', $filenameAt))[0] = 0x100001; // odd => tagged, offset 0x100000

        $this->expectException(OpCacheException::class);
        $this->expectExceptionMessageMatches('/interned-string offset|Malformed opcache payload/');
        (new PayloadRelocator($buffer, $meta))->relocate();
    }

    public function testValidPayloadStillRelocates(): void
    {
        // The guard must not reject a well-formed image (no false positives)
        [$payload, $meta] = $this->compiledPayload();
        $buffer           = $this->bufferOf($payload);
        $relocator        = new PayloadRelocator($buffer, $meta);
        $relocator->relocate();

        self::assertSame($payload, $relocator->derelocate());
    }

    /**
     * @return array{string, CacheMetaInfo}
     */
    private function compiledPayload(): array
    {
        $fixture = self::fixturePath();
        $binPath = self::compileFixture($fixture);
        $payload = substr((string) file_get_contents($binPath), CacheMetaInfo::byteSize());
        $meta    = CacheMetaInfo::parse((string) file_get_contents($binPath), $binPath);

        return [$payload, $meta];
    }

    /**
     * @return \FFI\CData a writable char[capacity] holding $payload
     */
    private function bufferOf(string $payload, ?int $capacity = null): object
    {
        $capacity = $capacity ?? strlen($payload);
        $buffer   = Core::new("char[{$capacity}]", false);
        if ($payload !== '') {
            Core::memcpy($buffer, $payload, strlen($payload));
        }

        return $buffer;
    }
}
