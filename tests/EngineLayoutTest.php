<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine;

use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the running engine's ABI matches the generated ground truth.
 *
 * This is the anti-segfault backbone: if FFI's view of any struct differs from
 * what the C compiler recorded in layouts.json, z-engine would read and write
 * the wrong memory. Keeping this green is what makes it safe to poke at engine
 * internals at all.
 */
final class EngineLayoutTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array{size: int, fields: array<string, int>}}>
     */
    public static function structLayoutProvider(): array
    {
        $layouts = self::loadLayouts();
        $cases   = [];
        foreach ($layouts['structs'] as $struct => $layout) {
            $cases[$struct] = [$struct, $layout];
        }

        return $cases;
    }

    /**
     * @param array{size: int, fields: array<string, int>} $layout
     */
    #[DataProvider('structLayoutProvider')]
    public function testStructLayoutMatchesGroundTruth(string $struct, array $layout): void
    {
        $type = Core::type($struct);

        $this->assertSame(
            $layout['size'],
            $type->getSize(),
            "sizeof({$struct}) reported by FFI differs from the C compiler ground truth",
        );

        foreach ($layout['fields'] as $field => $expectedOffset) {
            $this->assertSame(
                $expectedOffset,
                $type->getStructFieldOffset($field),
                "offsetof({$struct}, {$field}) reported by FFI differs from the C compiler ground truth",
            );
        }
    }

    public function testLayoutsWereProbedForTheRunningPhpMinor(): void
    {
        $meta         = self::loadLayouts()['meta'];
        $runningMinor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        $this->assertSame($runningMinor, $meta['php'], 'Bundled layouts were probed for a different PHP minor version');
        $this->assertSame((int) ZEND_THREAD_SAFE, $meta['zts'], 'Bundled layouts have a different thread-safety mode');
    }

    /**
     * @return array{meta: array{php: string, api: int, zts: int, debug: int}, structs: array<string, array{size: int, fields: array<string, int>}>}
     */
    private static function loadLayouts(): array
    {
        $arch        = php_uname('m');
        $platformKey = sprintf(
            '%d.%d/%s-%s-%s',
            PHP_MAJOR_VERSION,
            PHP_MINOR_VERSION,
            strtolower(PHP_OS_FAMILY),
            match ($arch) {
                'x86_64', 'amd64'  => 'x64',
                'aarch64', 'arm64' => 'arm64',
                default            => $arch,
            },
            ZEND_THREAD_SAFE ? 'zts' : 'nts',
        );
        $file = __DIR__ . '/../include/' . $platformKey . '/layouts.json';

        return json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
    }
}
