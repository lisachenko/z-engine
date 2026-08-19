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
use ZEngine\Reflection\ReflectionValue;

/**
 * Relocation coverage for dynamic function definitions (issue #115): arrow
 * functions and anonymous closures - including a closure nested inside another
 * closure and one inside a method - must round-trip byte-for-byte and execute
 * after a patch-and-save cycle.
 */
#[Group('opcache')]
#[Group('opcache-relocator')]
final class ClosureRelocationTest extends TestCase
{
    use FileCacheFixture;

    protected function setUp(): void
    {
        if (!PayloadRelocator::isSupported()) {
            self::markTestSkipped(
                'The file-cache relocator supports 64-bit POSIX NTS payloads only'
                . ' (ZTS is issue #118, Windows is issue #119)',
            );
        }
    }

    protected function tearDown(): void
    {
        self::removeCacheDir();
    }

    public function testClosurePayloadRoundTripsByteIdentical(): void
    {
        $binPath = self::compileFixture(self::closureFixturePath());
        $payload = substr((string) file_get_contents($binPath), CacheMetaInfo::byteSize());
        $meta    = CacheMetaInfo::parse((string) file_get_contents($binPath), $binPath);

        $length = strlen($payload);
        $buffer = Core::new("char[{$length}]", false);
        Core::memcpy($buffer, $payload, $length);
        $relocator = new PayloadRelocator($buffer, $meta);
        $relocator->relocate();

        self::assertSame($payload, $relocator->derelocate(), 'A closure round trip must reproduce the payload byte-for-byte');
    }

    public function testUnmodifiedResaveStillExecutes(): void
    {
        $fixture = self::closureFixturePath();
        $file    = BinaryCacheFile::read(self::compileFixture($fixture), $fixture);
        $file->getReflection(); // relocate
        $file->save();          // re-serialize unchanged

        self::assertTrue(BinaryCacheFile::read($file->binPath())->verifyChecksum());
        self::assertSame(
            'cl:42:42:cl-ok',
            self::runFromCache($fixture, 'zengine_bin_closures_run', self::$cacheDir),
        );
    }

    public function testPatchedClosureFixtureExecutesFromCache(): void
    {
        $fixture = self::closureFixturePath();
        $file    = BinaryCacheFile::read(self::compileFixture($fixture), $fixture);
        self::patchStringLiteral($file, 'zengine_bin_closures_run', ':cl-ok', ':cl-patched-through-the-relocator');
        $file->save();

        self::assertSame(
            'cl:42:42:cl-patched-through-the-relocator',
            self::runFromCache($fixture, 'zengine_bin_closures_run', self::$cacheDir),
        );
    }

    private static function closureFixturePath(): string
    {
        $path = realpath(__DIR__ . '/fixtures/closures.php');
        self::assertIsString($path);

        return $path;
    }

    private static function patchStringLiteral(BinaryCacheFile $file, string $function, string $from, string $to): void
    {
        $functions = $file->getReflection()->getFunctions();
        self::assertArrayHasKey($function, $functions, "Function {$function} not found in the cached script");
        foreach ($functions[$function]->getLiterals() as $literal) {
            if ($literal->getBaseType() !== ReflectionValue::IS_STRING) {
                continue;
            }
            $literal->getNativeValue($value);
            if ($value === $from) {
                $literal->setNativeValue($to);

                return;
            }
        }
        self::fail("String literal '{$from}' not found in {$function}");
    }
}
