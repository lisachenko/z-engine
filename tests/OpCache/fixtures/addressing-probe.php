<?php

/**
 * Fixture compiled into the opcache file cache by the opcode-addressing tests.
 *
 * The top-level code is built so its main op_array is guaranteed to carry both
 * IS_CONST operands and conditional jumps after optimization: getenv() is
 * opaque to SCCP, so neither the ?: nor the !== branch can be folded away.
 */
declare(strict_types=1);

$zengineProbeSeed = getenv('ZENGINE_PROBE_SEED') ?: 'seed';
if ($zengineProbeSeed !== 'expected-marker') {
    $zengineProbeSeed .= ':fallback-branch';
}

function zengine_bin_probe(): string
{
    return 'probe-ok';
}
