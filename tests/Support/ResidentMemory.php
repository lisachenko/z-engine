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

namespace ZEngine\Support;

/**
 * Portable resident-set-size sampling for the flat-memory tests: the churn
 * loops assert that repeated allocate/free cycles leave the process RSS flat,
 * which PHP's own memory_get_usage() cannot see (persistent blocks live
 * outside the request allocator).
 */
final class ResidentMemory
{
    /**
     * Current RSS in KiB, or 0 when it cannot be sampled on this platform
     * (callers skip their assertion in that case).
     */
    public static function kiloBytes(): int
    {
        // Linux: VmRSS from procfs - no subprocess, exact same field the
        // original implementation used.
        if (is_readable('/proc/self/status')) {
            foreach (file('/proc/self/status') ?: [] as $line) {
                if (str_starts_with($line, 'VmRSS:')) {
                    return (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                }
            }

            return 0;
        }

        // macOS/BSD have no procfs; ps reports rss in KiB with the same
        // "currently resident" semantics as VmRSS.
        $rss = shell_exec('ps -o rss= -p ' . getmypid() . ' 2>/dev/null');

        return is_string($rss) ? (int) trim($rss) : 0;
    }
}
