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
 * The decisive tests for the relocate/serialize pipeline: byte-identity of an
 * untouched round trip, and that a patched binary is what the engine executes.
 */
#[Group('opcache')]
#[Group('opcache-relocator')]
final class SerializerRoundTripTest extends TestCase
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

    public function testRelocateThenDerelocateIsByteIdentical(): void
    {
        $binPath = self::compileFixture();
        $payload = substr((string) file_get_contents($binPath), CacheMetaInfo::byteSize());
        $meta    = CacheMetaInfo::parse((string) file_get_contents($binPath), $binPath);

        $length = strlen($payload);
        $buffer = Core::new("char[{$length}]", false);
        Core::memcpy($buffer, $payload, $length);
        $relocator = new PayloadRelocator($buffer, $meta);
        $relocator->relocate();

        self::assertSame($payload, $relocator->derelocate(), 'An untouched round trip must reproduce the payload byte-for-byte');
    }

    public function testUnmodifiedResaveStillExecutes(): void
    {
        $file = BinaryCacheFile::read(self::compileFixture(), self::fixturePath());
        $file->getReflection(); // relocate
        $file->save();   // re-serialize unchanged

        self::assertTrue(BinaryCacheFile::read($file->binPath())->verifyChecksum());
        self::assertSame('41', self::runFromCache(self::fixturePath(), 'zengine_bin_answer', self::$cacheDir));
    }

    public function testPatchedIntegerLiteralIsExecutedFromCache(): void
    {
        $file = BinaryCacheFile::read(self::compileFixture(), self::fixturePath());
        self::patchAnswerLiteral($file, 41, 42);
        $file->save();

        self::assertSame('42', self::runFromCache(self::fixturePath(), 'zengine_bin_answer', self::$cacheDir));
    }

    public function testSizeChangingStringPatchIsExecutedFromCache(): void
    {
        $file = BinaryCacheFile::read(self::compileFixture(), self::fixturePath());
        self::patchGreetingLiteral($file, 'hello', 'hello there, patched world');
        $file->save();

        self::assertSame('hello there, patched world', self::runFromCache(self::fixturePath(), 'zengine_bin_greeting', self::$cacheDir));
    }

    private static function patchAnswerLiteral(BinaryCacheFile $file, int $from, int $to): void
    {
        foreach (self::functionLiterals($file, 'zengine_bin_answer') as $literal) {
            $literal->getNativeValue($value);
            if ($literal->getBaseType() === ReflectionValue::IS_LONG && $value === $from) {
                $literal->setNativeValue($to);

                return;
            }
        }
        self::fail("Literal {$from} not found in zengine_bin_answer");
    }

    private static function patchGreetingLiteral(BinaryCacheFile $file, string $from, string $to): void
    {
        foreach (self::functionLiterals($file, 'zengine_bin_greeting') as $literal) {
            if ($literal->getBaseType() !== ReflectionValue::IS_STRING) {
                continue;
            }
            $literal->getNativeValue($value);
            if ($value === $from) {
                $literal->setNativeValue($to);

                return;
            }
        }
        self::fail("String literal '{$from}' not found in zengine_bin_greeting");
    }

    /**
     * @return iterable<ReflectionValue>
     */
    private static function functionLiterals(BinaryCacheFile $file, string $functionName): iterable
    {
        $functions = $file->getReflection()->getFunctions();
        if (!isset($functions[$functionName])) {
            self::fail("Function {$functionName} not found in the cached script");
        }

        return $functions[$functionName]->getLiterals();
    }
}
