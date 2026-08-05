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
use ZEngine\Reflection\ReflectionValue;

/**
 * The full vision loop end to end: compile a script into the file cache,
 * mutate its compiled body through the framework, refresh the cache, and prove
 * a fresh worker executes the patched code.
 */
final class RefreshWorkflowTest extends TestCase
{
    use FileCacheFixture;

    protected function tearDown(): void
    {
        self::removeCacheDir();
    }

    public function testCompilePatchRefreshRunLoop(): void
    {
        // compile -> the cache holds the untouched fixture
        $file = BinaryCacheFile::compile(self::fixturePath(), self::freshCacheDir());
        self::assertSame('41', self::runFromCache(self::fixturePath(), 'zengine_bin_answer', self::$cacheDir));

        // mutate the compiled literal through the reflection wrappers
        $function = $file->getReflection()->getFunctions()['zengine_bin_answer'];
        foreach ($function->getLiterals() as $literal) {
            $literal->getNativeValue($value);
            if ($literal->getBaseType() === ReflectionValue::IS_LONG && $value === 41) {
                $literal->setNativeValue(42);
            }
        }

        // refresh -> write the patched binary + invalidate the source script
        $file->refresh();

        // a fresh worker now executes the patched body straight from the cache
        self::assertSame('42', self::runFromCache(self::fixturePath(), 'zengine_bin_answer', self::$cacheDir));
    }

    private static function freshCacheDir(): string
    {
        if (!extension_loaded('Zend OPcache')) {
            self::markTestSkipped('Zend OPcache extension is not loaded');
        }
        self::$cacheDir = sys_get_temp_dir() . '/zengine-opcache-' . bin2hex(random_bytes(6));

        return self::$cacheDir;
    }
}
