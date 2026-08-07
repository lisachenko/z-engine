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
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use ZEngine\Core;

/**
 * The node shapes PHP 8.5 introduced into the file-cache format: an attributed
 * global constant (ZEND_DECLARE_ATTRIBUTED_CONST + ZEND_OP_DATA carrying an
 * IS_PTR attribute-table literal) and a static closure compiled into a constant
 * expression (ZEND_AST_OP_ARRAY).
 *
 * The fixture uses 8.5-only syntax, so these tests carry their own group
 * (`opcache-php85`) instead of `opcache`: the `opcache` group runs under
 * --fail-on-skipped on EVERY supported minor, while this group is asserted
 * skip-free by `composer test:opcache85` on the minors that have the syntax.
 */
#[Group('opcache-php85')]
#[RequiresPhp('>= 8.5.0')]
final class Php85SerializerShapesTest extends TestCase
{
    use FileCacheFixture;

    protected function tearDown(): void
    {
        self::removeCacheDir();
    }

    public function testConstantShapesRoundTripByteIdentical(): void
    {
        $binPath = self::compileFixture(self::php85FixturePath());
        $payload = substr((string) file_get_contents($binPath), CacheMetaInfo::byteSize());
        $meta    = CacheMetaInfo::parse((string) file_get_contents($binPath), $binPath);

        $length = strlen($payload);
        $buffer = Core::new("char[{$length}]", false);
        Core::memcpy($buffer, $payload, $length);
        $relocator = new PayloadRelocator($buffer, $meta);
        $relocator->relocate();

        self::assertSame($payload, $relocator->derelocate(), 'The 8.5 node shapes must survive an untouched round trip byte-for-byte');
    }

    public function testConstantShapesExecuteAfterResave(): void
    {
        $fixture = self::php85FixturePath();
        $file    = BinaryCacheFile::read(self::compileFixture($fixture), $fixture);
        $file->getReflection(); // relocate
        $file->save();   // re-serialize unchanged

        self::assertTrue(BinaryCacheFile::read($file->binPath())->verifyChecksum());
        self::assertSame('on5', self::runFromCache($fixture, 'zengine_bin_php85', self::$cacheDir));
    }

    /**
     * The attributed-constant and const-closure shapes exist only since PHP 8.5 and
     * use 8.5-only syntax, so they live in their own fixture the 8.4 runs never parse
     */
    private static function php85FixturePath(): string
    {
        $path = realpath(__DIR__ . '/fixtures/answer-php85.php');
        self::assertIsString($path);

        return $path;
    }
}
