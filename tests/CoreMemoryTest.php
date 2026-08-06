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

namespace ZEngine;

use PHPUnit\Framework\TestCase;

/**
 * Covers the low-level allocation primitives of Core: the tracked-block registry and the
 * raw persistent free() that releases malloc-backed blocks after their allocating request
 * is long gone (see docs/long-running.md).
 */
final class CoreMemoryTest extends TestCase
{
    public function testTrackedPersistentBlockIsRecognizedAndUntrackable(): void
    {
        $block   = Core::trackedNew('char[128]', true);
        $pointer = Core::cast('char *', $block);

        $this->assertTrue(Core::isTrackedBlock($pointer));

        Core::untrack($pointer);
        $this->assertFalse(Core::isTrackedBlock($pointer), 'untrack() must drop the bookkeeping only');

        // Ownership was handed over by untrack(), so the block is ours to free by hand
        Core::persistentFree($pointer);
    }

    public function testPersistentFreeReleasesMallocBackedBlocks(): void
    {
        $residentKiloBytes = static function (): int {
            foreach (file('/proc/self/status') ?: [] as $line) {
                if (str_starts_with($line, 'VmRSS:')) {
                    return (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                }
            }

            return 0;
        };

        if ($residentKiloBytes() === 0) {
            self::markTestSkipped('VmRSS is not readable on this platform');
        }

        $churn = static function (int $cycles): void {
            for ($cycle = 0; $cycle < $cycles; $cycle++) {
                // 256 KiB per cycle: not freeing this is visible in RSS within a few cycles
                $block   = Core::trackedNew('char[262144]', true);
                $pointer = Core::cast('char *', $block);
                Core::memcpy($pointer, str_repeat('z', 4096), 4096);

                Core::untrack($pointer);
                Core::persistentFree($pointer);
            }
        };

        $churn(16);
        $baseline = $residentKiloBytes();
        $churn(256);

        $this->assertLessThan(
            2_048,
            $residentKiloBytes() - $baseline,
            'persistentFree() did not return 64 MiB of malloc traffic to the allocator',
        );
    }

    public function testPersistentFreeIsOrthogonalToTheTrackedRegistry(): void
    {
        // The registry is a PHP static and dies with its request; persistentFree() is the
        // cross-request path and therefore deliberately does not consult (or update) it
        $block   = Core::trackedNew('char[64]', true);
        $pointer = Core::cast('char *', $block);

        Core::persistentFree($pointer);

        $this->assertTrue(
            Core::isTrackedBlock($pointer),
            'persistentFree() must leave the tracked-block registry untouched',
        );

        // ...which is exactly why a caller freeing a same-request block must untrack it:
        // a stale entry would let untrackAndFree() free the very same address again
        Core::untrack($pointer);
        $this->assertFalse(Core::isTrackedBlock($pointer));
    }

    public function testPointerAtAddressTargetsTheGivenAddress(): void
    {
        $block   = Core::trackedNew('char[64]', true);
        $pointer = Core::cast('char *', $block);
        $address = Core::addressOf($pointer);

        $materialized = Core::pointerAtAddress('char *', $address);

        $this->assertSame(
            $address,
            Core::addressOf($materialized),
            'pointerAtAddress() must be the exact inverse of addressOf()',
        );

        Core::untrack($pointer);
        Core::persistentFree($pointer);
    }

    /**
     * pointerAtAddress() is the primitive behind every walk to a raw engine address (a
     * hashtable construction alone calls it once per table), so it has to be free of any
     * per-call allocation. It used to write the address through an integer view of a
     * fresh `{$type}[1]` slot and return `$slot[0]`; that element view pins its owning
     * slot for as long as it lives, so every single call retained ~116 bytes for the rest
     * of the request - about 5.8 MB over the loop below.
     */
    public function testPointerAtAddressDoesNotAllocatePerCall(): void
    {
        $residentKiloBytes = static function (): int {
            foreach (file('/proc/self/status') ?: [] as $line) {
                if (str_starts_with($line, 'VmRSS:')) {
                    return (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                }
            }

            return 0;
        };

        if ($residentKiloBytes() === 0) {
            self::markTestSkipped('VmRSS is not readable on this platform');
        }

        $block   = Core::trackedNew('char[64]', true);
        $address = Core::addressOf(Core::cast('char *', $block));

        // Warm up so lazily built FFI state is not counted as growth
        for ($cycle = 0; $cycle < 1_000; $cycle++) {
            Core::pointerAtAddress('char *', $address);
        }
        $baseline = $residentKiloBytes();

        for ($cycle = 0; $cycle < 50_000; $cycle++) {
            Core::pointerAtAddress('char *', $address);
        }

        // The leaking implementation grew ~5.5 MB here; anything under a megabyte means
        // the calls are not retaining a buffer each
        $this->assertLessThan(
            1_024,
            $residentKiloBytes() - $baseline,
            'pointerAtAddress() retained memory per call',
        );

        Core::untrack(Core::cast('char *', $block));
        Core::persistentFree(Core::cast('char *', $block));
    }
}
