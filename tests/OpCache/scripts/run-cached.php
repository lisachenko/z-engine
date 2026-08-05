<?php

/**
 * Child driver for the OpCache patch tests: loads a script strictly from the
 * file cache (opcache.file_cache_only=1) and prints the runtime result, so the
 * parent can prove the engine executed the patched cache binary.
 *
 * argv: [1] = script to include (its .bin must already exist in the cache dir)
 *       [2] = a global function to call and print, or a "Class::method" pair
 */
declare(strict_types=1);

if (!function_exists('opcache_get_status')) {
    fwrite(STDERR, "opcache not available\n");
    exit(2);
}

$script = $argv[1] ?? '';
$target = $argv[2] ?? '';
require $script;

// With file_cache_only the loaded value is itself the proof: the parent's
// patched binary yields a value the untouched source never would, so a match
// means the engine executed the patched cache binary rather than recompiling.
if (str_contains($target, '::')) {
    [$class, $method] = explode('::', $target, 2);
    echo $class::$method(), "\n";
} else {
    echo $target(), "\n";
}
