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
#[Group('opcache-relocator')]
final class ReflectionOpcacheFileTest extends TestCase
{
    use FileCacheFixture;

    protected function setUp(): void
    {
        if (\ZEND_THREAD_SAFE) {
            self::markTestSkipped('The file-cache relocator does not support ZTS payloads yet (issue #118)');
        }
    }

    protected function tearDown(): void
    {
        self::removeCacheDir();
    }

    public function testWalksFunctionsThroughReflectionWrappers(): void
    {
        $file = BinaryCacheFile::read(self::compileFixture(), self::fixturePath());
        $view = $file->getReflection();

        self::assertStringEndsWith('answer.php', $view->getFileName());

        // getFunctions() is name-keyed (parity with ReflectionExtension), and
        // each value is a working ReflectionFunction over the cached body
        $functions = $view->getFunctions();
        self::assertArrayHasKey('zengine_bin_answer', $functions);
        self::assertArrayHasKey('zengine_bin_greeting', $functions);
        self::assertGreaterThan(0, count([...$functions['zengine_bin_answer']->getOpCodes()]));
    }

    public function testWalksClassesThroughReflectionWrappers(): void
    {
        $file = BinaryCacheFile::read(self::compileFixture(), self::fixturePath());
        $view = $file->getReflection();

        $classes = $view->getClasses();
        self::assertArrayHasKey('zenginebinsubject', $classes);
        self::assertArrayHasKey('zenginebinmarker', $classes);
        self::assertSame('ZEngineBinSubject', (string) $classes['zenginebinsubject']->getName());
    }

    public function testMainOpArrayIsReadable(): void
    {
        $file = BinaryCacheFile::read(self::compileFixture(), self::fixturePath());

        $main    = $file->getReflection()->getScriptFunction();
        $opCodes = [...$main->getOpCodes()];
        self::assertGreaterThan(0, count($opCodes));
    }

    public function testScriptRejectsForeignBuildPayload(): void
    {
        $binPath = self::compileFixture();
        $foreign = self::$cacheDir . '/foreign.bin';
        $bytes   = (string) file_get_contents($binPath);
        $meta    = CacheMetaInfo::parse($bytes, $binPath);
        $rebuilt = CacheMetaInfo::forPayload(
            SystemId::fromBinary(str_repeat('ab', 16)),
            $meta->memSize(),
            $meta->strSize(),
            $meta->scriptOffset(),
            $meta->timestamp(),
            $meta->checksum(),
        );
        file_put_contents($foreign, $rebuilt->toBinary() . substr($bytes, CacheMetaInfo::byteSize()));

        $this->expectException(OpCacheException::class);
        $this->expectExceptionMessage('was produced by build');
        BinaryCacheFile::read($foreign)->getReflection();
    }
}
