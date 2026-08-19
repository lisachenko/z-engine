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
 * Relocation coverage for zend_type_list payloads (issue #112): union,
 * intersection and DNF types in parameters, return types and properties must
 * round-trip byte-for-byte and still execute after a patch-and-save cycle.
 */
#[Group('opcache')]
#[Group('opcache-relocator')]
final class TypeListRelocationTest extends TestCase
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

    public function testTypeListPayloadRoundTripsByteIdentical(): void
    {
        $binPath = self::compileFixture(self::typeListFixturePath());
        $payload = substr((string) file_get_contents($binPath), CacheMetaInfo::byteSize());
        $meta    = CacheMetaInfo::parse((string) file_get_contents($binPath), $binPath);

        $length = strlen($payload);
        $buffer = Core::new("char[{$length}]", false);
        Core::memcpy($buffer, $payload, $length);
        $relocator = new PayloadRelocator($buffer, $meta);
        $relocator->relocate();

        self::assertSame($payload, $relocator->derelocate(), 'A type-list round trip must reproduce the payload byte-for-byte');
    }

    public function testUnmodifiedResaveStillExecutes(): void
    {
        $fixture = self::typeListFixturePath();
        $file    = BinaryCacheFile::read(self::compileFixture($fixture), $fixture);
        $file->getReflection(); // relocate
        $file->save();          // re-serialize unchanged

        self::assertTrue(BinaryCacheFile::read($file->binPath())->verifyChecksum());
        self::assertSame(
            'ZEngineTypeListImpl:tl-ok',
            self::runFromCache($fixture, 'zengine_bin_typelist_run', self::$cacheDir),
        );
    }

    public function testPatchedTypeListFixtureExecutesFromCache(): void
    {
        $fixture = self::typeListFixturePath();
        $file    = BinaryCacheFile::read(self::compileFixture($fixture), $fixture);
        self::patchStringLiteral($file, 'zengine_bin_typelist_run', ':tl-ok', ':tl-patched-through-the-relocator');
        $file->save();

        self::assertSame(
            'ZEngineTypeListImpl:tl-patched-through-the-relocator',
            self::runFromCache($fixture, 'zengine_bin_typelist_run', self::$cacheDir),
        );
    }

    private static function typeListFixturePath(): string
    {
        $path = realpath(__DIR__ . '/fixtures/type-lists.php');
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
