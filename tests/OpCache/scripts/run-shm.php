<?php

/**
 * Child driver for the shared-memory refresh tests: includes a value-returning
 * fixture with opcache shared memory ACTIVE (opcache.file_cache set, but NOT
 * file_cache_only) and reports what executed and whether the script ended up
 * resident in shared memory.
 *
 * A fresh worker starts with an empty SHM, so its include goes SHM-miss ->
 * file-cache load: `value` is the body the engine actually executed and
 * `shm=1` proves the cache binary passed opcache's own loader checks INTO
 * shared memory (opcache_is_script_cached() is false for process-memory
 * fallbacks).
 *
 * argv: [1] = fixture script to include (must `return` a value)
 *
 * Output: "value=<v> shm=<0|1>". Exit 2 when opcache shared memory is not
 * active in this process - the parent skips, never a silent pass.
 */
declare(strict_types=1);

$status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
if (!is_array($status) || !empty($status['file_cache_only'])) {
    fwrite(STDERR, "opcache shared memory is not active in the child process\n");
    exit(2);
}

$fixture = realpath($argv[1] ?? '');
if ($fixture === false) {
    fwrite(STDERR, "fixture script not found\n");
    exit(1);
}

$value = include $fixture;
if (!is_int($value)) {
    fwrite(STDERR, 'the fixture must return an int, got ' . var_export($value, true) . "\n");
    exit(1);
}
$shm = opcache_is_script_cached($fixture) ? 1 : 0;

echo "value={$value} shm={$shm}\n";
