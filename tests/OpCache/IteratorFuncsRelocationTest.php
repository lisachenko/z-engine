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
 * Relocation coverage for iterator_funcs_ptr / arrayaccess_funcs_ptr payloads
 * (issue #116). Classes compiled into the file cache are stored unlinked (the
 * compiler does not early-bind classes that implement interfaces), so the
 * Iterator / IteratorAggregate / ArrayAccess fixture proves such classes
 * round-trip byte-for-byte and execute after a patch-and-save cycle; the
 * crafted-buffer test drives the pointer walk itself, which only fires for
 * payloads whose classes were persisted linked.
 */
#[Group('opcache')]
#[Group('opcache-relocator')]
final class IteratorFuncsRelocationTest extends TestCase
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

    public function testIteratorPayloadRoundTripsByteIdentical(): void
    {
        $binPath = self::compileFixture(self::iteratorFixturePath());
        $payload = substr((string) file_get_contents($binPath), CacheMetaInfo::byteSize());
        $meta    = CacheMetaInfo::parse((string) file_get_contents($binPath), $binPath);

        $length = strlen($payload);
        $buffer = Core::new("char[{$length}]", false);
        Core::memcpy($buffer, $payload, $length);
        $relocator = new PayloadRelocator($buffer, $meta);
        $relocator->relocate();

        self::assertSame($payload, $relocator->derelocate(), 'An iterator round trip must reproduce the payload byte-for-byte');
    }

    public function testUnmodifiedResaveStillExecutes(): void
    {
        $fixture = self::iteratorFixturePath();
        $file    = BinaryCacheFile::read(self::compileFixture($fixture), $fixture);
        $file->getReflection(); // relocate
        $file->save();          // re-serialize unchanged

        self::assertTrue(BinaryCacheFile::read($file->binPath())->verifyChecksum());
        self::assertSame(
            'alpha:beta:gamma:delta:it-ok',
            self::runFromCache($fixture, 'zengine_bin_iterators_run', self::$cacheDir),
        );
    }

    public function testPatchedIteratorFixtureExecutesFromCache(): void
    {
        $fixture = self::iteratorFixturePath();
        $file    = BinaryCacheFile::read(self::compileFixture($fixture), $fixture);
        self::patchStringLiteral($file, 'zengine_bin_iterators_run', ':it-ok', ':it-patched-through-the-relocator');
        $file->save();

        self::assertSame(
            'alpha:beta:gamma:delta:it-patched-through-the-relocator',
            self::runFromCache($fixture, 'zengine_bin_iterators_run', self::$cacheDir),
        );
    }

    /**
     * Drives the ported iterator_funcs_ptr / arrayaccess_funcs_ptr walk against a
     * crafted image: a zend_class_entry whose struct pointers and zf_* members hold
     * serialized offsets (NULL slots included) must relocate to real addresses and
     * serialize back to the exact original bytes.
     */
    public function testCraftedIteratorFuncsRelocateAndSerializeBack(): void
    {
        $memSize = 4096;
        $buffer  = Core::new("char[{$memSize}]", false);
        $meta    = CacheMetaInfo::forPayload(
            systemId: SystemId::current(),
            memSize: $memSize,
            strSize: 0,
            scriptOffset: 0,
            timestamp: 0,
            checksum: 0,
        );
        $relocator = new PayloadRelocator($buffer, $meta);
        $base      = Core::addressOf(Core::addr($buffer));

        $ceOffset          = 512;
        $iteratorOffset    = 1024;
        $arrayAccessOffset = 1280;
        $iteratorSlots     = [
            'zf_new_iterator' => 2048,
            'zf_rewind'       => 2112,
            'zf_valid'        => 2176,
            'zf_key'          => 0, // NULL member must stay NULL through both directions
            'zf_current'      => 2240,
            'zf_next'         => 2304,
        ];
        $arrayAccessSlots = [
            'zf_offsetget'    => 2368,
            'zf_offsetexists' => 2432,
            'zf_offsetset'    => 0,
            'zf_offsetunset'  => 2496,
        ];

        $ce                        = Core::pointerAtAddress('zend_class_entry *', $base + $ceOffset);
        $ce->iterator_funcs_ptr    = Core::pointerAtAddress('zend_class_iterator_funcs *', $iteratorOffset);
        $ce->arrayaccess_funcs_ptr = Core::pointerAtAddress('zend_class_arrayaccess_funcs *', $arrayAccessOffset);
        $iteratorFuncs             = Core::pointerAtAddress('zend_class_iterator_funcs *', $base + $iteratorOffset);
        foreach ($iteratorSlots as $field => $offset) {
            $iteratorFuncs->$field = $offset === 0 ? null : Core::pointerAtAddress('zend_function *', $offset);
        }
        $arrayAccessFuncs = Core::pointerAtAddress('zend_class_arrayaccess_funcs *', $base + $arrayAccessOffset);
        foreach ($arrayAccessSlots as $field => $offset) {
            $arrayAccessFuncs->$field = $offset === 0 ? null : Core::pointerAtAddress('zend_function *', $offset);
        }
        $serializedBytes = \FFI::string($buffer, $memSize);

        $unserialize = new \ReflectionMethod(PayloadRelocator::class, 'unserializeIteratorFuncs');
        $unserialize->invoke($relocator, $ce);

        self::assertSame($base + $iteratorOffset, Core::addressOf($ce->iterator_funcs_ptr));
        self::assertSame($base + $arrayAccessOffset, Core::addressOf($ce->arrayaccess_funcs_ptr));
        foreach ($iteratorSlots as $field => $offset) {
            self::assertMemberRelocated($iteratorFuncs->$field, $base, $offset, $field);
        }
        foreach ($arrayAccessSlots as $field => $offset) {
            self::assertMemberRelocated($arrayAccessFuncs->$field, $base, $offset, $field);
        }

        $serialize = new \ReflectionMethod(PayloadRelocator::class, 'serializeIteratorFuncs');
        $serialize->invoke($relocator, $ce);

        self::assertSame($serializedBytes, \FFI::string($buffer, $memSize), 'Serializing back must restore the exact original bytes');
    }

    private static function assertMemberRelocated(mixed $member, int $base, int $offset, string $field): void
    {
        if ($offset === 0) {
            self::assertNull($member, "{$field} must stay NULL");

            return;
        }
        self::assertInstanceOf(\FFI\CData::class, $member, "{$field} must still be a pointer");
        self::assertSame($base + $offset, Core::addressOf($member), "{$field} must be relocated");
    }

    private static function iteratorFixturePath(): string
    {
        $path = realpath(__DIR__ . '/fixtures/iterators.php');
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
