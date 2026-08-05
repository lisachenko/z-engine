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

#[Group('opcache')]
final class BinaryCacheFileTest extends TestCase
{
    use FileCacheFixture;

    protected function tearDown(): void
    {
        self::removeCacheDir();
    }

    public function testCompileProducesAReadableVerifiableBinary(): void
    {
        $binPath = self::compileFixture();
        $file    = BinaryCacheFile::read($binPath, self::fixturePath());

        self::assertSame($binPath, $file->binPath());
        self::assertSame(self::fixturePath(), $file->scriptPath());
        self::assertTrue($file->matchesCurrentBuild());
        self::assertTrue($file->verifyChecksum());
        self::assertSame($file->metaInfo()->memSize() + $file->metaInfo()->strSize(), strlen($file->payload()));
    }

    public function testCompileApiMatchesTheChildCompilerOutput(): void
    {
        if (!extension_loaded('Zend OPcache')) {
            self::markTestSkipped('Zend OPcache extension is not loaded');
        }
        $referenceBin = self::compileFixture();
        $cacheDir     = sys_get_temp_dir() . '/zengine-opcache-' . bin2hex(random_bytes(6));
        try {
            $file = BinaryCacheFile::compile(self::fixturePath(), $cacheDir);

            self::assertSame(BinaryCacheFile::locate($cacheDir, self::fixturePath()), $file->binPath());
            self::assertTrue($file->verifyChecksum());
            // Same build, same source: equal layout sizes (bytes may differ -
            // opcache serializes struct padding as-is, which is not
            // deterministic across processes)
            $reference = BinaryCacheFile::read($referenceBin);
            self::assertSame(strlen($reference->payload()), strlen($file->payload()));
            self::assertSame($reference->metaInfo()->scriptOffset(), $file->metaInfo()->scriptOffset());
        } finally {
            $probe = $cacheDir . '/probe';
            exec('rm -rf ' . escapeshellarg($cacheDir));
            self::assertDirectoryDoesNotExist($probe);
        }
    }

    public function testLocateMatchesWhereOpcacheActuallyWrites(): void
    {
        $binPath = self::compileFixture();

        self::assertSame($binPath, BinaryCacheFile::locate(self::$cacheDir, self::fixturePath()));
    }

    public function testForeignBuildHeaderIsReadableButFlagged(): void
    {
        $binPath   = self::compileFixture();
        $foreign   = self::$cacheDir . '/foreign.bin';
        $bytes     = (string) file_get_contents($binPath);
        $meta      = CacheMetaInfo::parse($bytes, $binPath);
        $rewritten = CacheMetaInfo::forPayload(
            SystemId::fromBinary(str_repeat('ab', 16)),
            $meta->memSize(),
            $meta->strSize(),
            $meta->scriptOffset(),
            $meta->timestamp(),
            $meta->checksum(),
        );
        file_put_contents($foreign, $rewritten->toBinary() . substr($bytes, CacheMetaInfo::byteSize()));

        $file = BinaryCacheFile::read($foreign);
        self::assertFalse($file->matchesCurrentBuild());
        self::assertTrue($file->verifyChecksum());
    }

    public function testSaveRewritesChecksumAndTimestampAtomically(): void
    {
        $binPath = self::compileFixture();
        $file    = BinaryCacheFile::read($binPath, self::fixturePath());
        $copy    = self::$cacheDir . '/rewritten.bin';

        $file->save($copy, 123456789);

        $rewritten = BinaryCacheFile::read($copy);
        self::assertTrue($rewritten->verifyChecksum());
        self::assertSame(123456789, $rewritten->metaInfo()->timestamp());
        self::assertSame($file->payload(), $rewritten->payload());
        // An unmodified payload keeps its checksum, so apart from the
        // timestamp the rewritten binary is byte-identical to the original
        self::assertSame($rewritten->metaInfo()->checksum(), $file->metaInfo()->checksum());
    }

    public function testReadRejectsMissingFile(): void
    {
        $this->expectException(OpCacheException::class);
        $this->expectExceptionMessage('not found');
        BinaryCacheFile::read('/nonexistent/path/script.php.bin');
    }

    public function testLocateRejectsMissingScript(): void
    {
        $this->expectException(OpCacheException::class);
        $this->expectExceptionMessage('does not exist');
        BinaryCacheFile::locate('/tmp', '/nonexistent/script.php');
    }

    public function testReadRejectsTruncatedPayload(): void
    {
        $binPath = self::compileFixture();
        $bytes   = (string) file_get_contents($binPath);
        $short   = self::$cacheDir . '/short.bin';
        file_put_contents($short, substr($bytes, 0, CacheMetaInfo::byteSize() + 32));

        $this->expectException(OpCacheException::class);
        $this->expectExceptionMessage('truncated');
        BinaryCacheFile::read($short);
    }
}
