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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The from-scratch persist-from-graph serializer (issue #117): every payload
 * shape must survive a full re-layout (rebuild an untouched image from its
 * graph and execute it), and the acceptance path - graft a brand-new function
 * and a new method into a cached script, save(), and have a fresh worker
 * execute them straight from the file cache.
 */
#[Group('opcache')]
#[Group('opcache-relocator')]
final class GraphGrowingSerializerTest extends TestCase
{
    use FileCacheFixture;

    /** @var list<string> extra cache directories to clean up */
    private array $extraCacheDirs = [];

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
        foreach ($this->extraCacheDirs as $directory) {
            self::removeDirectory($directory);
        }
        $this->extraCacheDirs = [];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function rebuildFixtures(): iterable
    {
        yield 'attributes + statics' => ['answer.php', 'zengine_bin_answer', '41'];
        yield 'type lists' => ['type-lists.php', 'zengine_bin_typelist_run', 'ZEngineTypeListImpl:tl-ok'];
        yield 'traits' => ['traits.php', 'zengine_bin_trait_run', 'greeter-shared:shouter-shared:greeter:tr-ok'];
        yield 'closures' => ['closures.php', 'zengine_bin_closures_run', 'cl:42:42:cl-ok'];
        yield 'property hooks' => ['property-hooks.php', 'zengine_bin_hooks_run', '0:40:gauge-40:0:ph-ok'];
        yield 'iterators' => ['iterators.php', 'zengine_bin_iterators_run', 'alpha:beta:gamma:delta:it-ok'];
        yield 'jumps and consts' => ['addressing-probe.php', 'zengine_bin_probe', 'probe-ok'];
    }

    /**
     * Rebuilding an UNTOUCHED image from its graph exercises every persist
     * walker; the rebuilt binary must be relocator-round-trippable and the
     * engine must execute it from the cache.
     */
    #[DataProvider('rebuildFixtures')]
    public function testRebuiltImageExecutesFromCache(string $fixtureName, string $target, string $expected): void
    {
        $fixture = self::fixtureByName($fixtureName);
        $file    = BinaryCacheFile::read(self::compileFixture($fixture), $fixture);

        $serializer = new ScriptSerializer($file->getReflection()->getRawScript());
        $payload    = $serializer->serialize();
        $meta       = CacheMetaInfo::forPayload(
            systemId: SystemId::current(),
            memSize: $serializer->memSize(),
            strSize: strlen($payload) - $serializer->memSize(),
            scriptOffset: $serializer->scriptOffset(),
            timestamp: 0,
            checksum: CacheMetaInfo::checksumOf($payload),
        );
        file_put_contents($file->binPath(), $meta->toBinary() . $payload);

        $rebuilt = BinaryCacheFile::read($file->binPath(), $fixture);
        self::assertTrue($rebuilt->verifyChecksum());
        $rebuilt->getReflection(); // relocate
        $rebuilt->save($file->binPath() . '.roundtrip');
        self::assertSame(
            (string) file_get_contents($file->binPath()),
            (string) file_get_contents($file->binPath() . '.roundtrip'),
            'The rebuilt image must round-trip byte-identically through the relocator',
        );
        unlink($file->binPath() . '.roundtrip');

        self::assertSame($expected, self::runFromCache($fixture, $target, self::$cacheDir));
    }

    /**
     * The issue #117 acceptance: a brand-new function AND a new method grafted
     * into a cached script execute from the file cache in a fresh worker.
     */
    public function testGraftedFunctionAndMethodExecuteFromCache(): void
    {
        $main     = self::fixtureByName('answer.php');
        $donorSrc = self::fixtureByName('graft-donor.php');

        $file     = BinaryCacheFile::read(self::compileFixture($main), $main);
        $donorDir = sys_get_temp_dir() . '/zengine-graft-donor-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($donorDir, 0777, true));
        $this->extraCacheDirs[] = $donorDir;
        $donor                  = BinaryCacheFile::compile($donorSrc, $donorDir);

        $view = $file->getReflection();
        self::assertFalse($view->isGraphGrown());
        $view->addFunctionFrom($donor->getReflection(), 'zengine_bin_added');
        $view->addMethodFrom($donor->getReflection(), 'ZEngineGraftDonor', 'addedReport', 'ZEngineBinSubject');
        self::assertTrue($view->isGraphGrown());
        $file->save();

        // The written binary is sound: checksum, reflection view, round trip
        $reread = BinaryCacheFile::read($file->binPath(), $main);
        self::assertTrue($reread->verifyChecksum());
        self::assertArrayHasKey('zengine_bin_added', $reread->getReflection()->getFunctions());
        $reread->save($file->binPath() . '.roundtrip');
        self::assertSame(
            (string) file_get_contents($file->binPath()),
            (string) file_get_contents($file->binPath() . '.roundtrip'),
            'The grown image must round-trip byte-identically through the relocator',
        );
        unlink($file->binPath() . '.roundtrip');

        // A fresh worker executes the grafts AND the original entries
        self::assertSame('added-fn', self::runFromCache($main, 'zengine_bin_added', self::$cacheDir));
        self::assertSame('added-method-ok', self::runFromCache($main, 'ZEngineBinSubject::addedReport', self::$cacheDir));
        self::assertSame('41', self::runFromCache($main, 'zengine_bin_answer', self::$cacheDir));
    }

    public function testGraftRefusesUnknownDonorEntries(): void
    {
        $main     = self::fixtureByName('answer.php');
        $donorSrc = self::fixtureByName('graft-donor.php');

        $file     = BinaryCacheFile::read(self::compileFixture($main), $main);
        $donorDir = sys_get_temp_dir() . '/zengine-graft-donor-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($donorDir, 0777, true));
        $this->extraCacheDirs[] = $donorDir;
        $donor                  = BinaryCacheFile::compile($donorSrc, $donorDir);

        $this->expectException(OpCacheException::class);
        $this->expectExceptionMessage("Cannot graft function 'zengine_bin_missing'");
        $file->getReflection()->addFunctionFrom($donor->getReflection(), 'zengine_bin_missing');
    }

    public function testGraftRefusesDuplicateKeys(): void
    {
        $main     = self::fixtureByName('answer.php');
        $donorSrc = self::fixtureByName('graft-donor.php');

        $file     = BinaryCacheFile::read(self::compileFixture($main), $main);
        $donorDir = sys_get_temp_dir() . '/zengine-graft-donor-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($donorDir, 0777, true));
        $this->extraCacheDirs[] = $donorDir;
        $donor                  = BinaryCacheFile::compile($donorSrc, $donorDir);

        $view = $file->getReflection();
        $view->addFunctionFrom($donor->getReflection(), 'zengine_bin_added');
        $this->expectException(OpCacheException::class);
        $this->expectExceptionMessage("Cannot graft 'zengine_bin_added'");
        $view->addFunctionFrom($donor->getReflection(), 'zengine_bin_added');
    }

    private static function fixtureByName(string $name): string
    {
        $path = realpath(__DIR__ . '/fixtures/' . $name);
        self::assertIsString($path);

        return $path;
    }
}
