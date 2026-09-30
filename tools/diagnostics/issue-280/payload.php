<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Deterministic debuggee for the issue #280 probes. Compiled AFTER the probe
 * installs its handler (and, for the EXT_STMT modes, after COMPILE_EXTENDED_STMT
 * is switched on), so every statement here dispatches through the hook.
 */
declare(strict_types=1);

fwrite(STDERR, "STAGE payload-first-statement\n");

class Probe280Service
{
    public function handle(int $value): int
    {
        $doubled = $value * 2;
        try {
            if ($value > 100) {
                throw new RuntimeException('expected');
            }
        } catch (RuntimeException) {
            $doubled += 200;
        }

        return $doubled;
    }
}

function probe280Helper(int $value): int
{
    return $value + 1;
}

$service = new Probe280Service();
$total   = 0;
foreach ([1, 2] as $value) {
    $total += $service->handle($value);
}
$total += probe280Helper(5);
$total += $service->handle(101);

echo 'PAYLOAD TOTAL=' . $total . "\n"; // 2 + 4 + 6 + 402 = 414, the canary
