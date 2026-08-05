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
use ZEngine\Type\StringEntry;

final class PersistentScriptViewTest extends TestCase
{
    use FileCacheFixture;

    protected function tearDown(): void
    {
        self::removeCacheDir();
    }

    public function testWalksFunctionsThroughReflectionWrappers(): void
    {
        $file = BinaryCacheFile::read(self::compileFixture(), self::fixturePath());
        $view = $file->script();

        self::assertStringEndsWith('answer.php', $view->scriptName());

        // The functions are not registered in this process, so their name is
        // read at the pointer level (native getName() needs a live reflection)
        $names = [];
        foreach ($view->functions() as $function) {
            $namePointer = $function->getCommonPointer()->function_name;
            self::assertNotNull($namePointer);
            $names[] = strtolower(StringEntry::fromCData($namePointer)->getStringValue());
        }
        self::assertContains('zengine_bin_answer', $names);
        self::assertContains('zengine_bin_greeting', $names);
    }

    public function testWalksClassesThroughReflectionWrappers(): void
    {
        $file = BinaryCacheFile::read(self::compileFixture(), self::fixturePath());
        $view = $file->script();

        $names = array_map(
            static fn(ReflectionClass $class): string => (string) $class->getName(),
            $view->classes(),
        );
        self::assertContains('ZEngineBinSubject', $names);
        self::assertContains('ZEngineBinMarker', $names);
    }

    public function testMainOpArrayIsReadable(): void
    {
        $file = BinaryCacheFile::read(self::compileFixture(), self::fixturePath());

        $main    = $file->script()->mainOpArray();
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
        BinaryCacheFile::read($foreign)->script();
    }
}
