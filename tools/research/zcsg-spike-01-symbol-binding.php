<?php

// SPIKE #121 (throwaway research, docs/research/zcsg-spike.md) - NOT wired into src/. Do not ship.
// SPIKE probe: can FFI::cdef (RTLD_DEFAULT on ELF) bind opcache-internal symbols?
declare(strict_types=1);

function tryBind(string $decl, string $name): string
{
    try {
        $ffi = FFI::cdef($decl); // null lib => RTLD_DEFAULT on ELF
        // Force symbol resolution by touching it
        $x = $ffi->$name;
        return 'BOUND ok';
    } catch (\FFI\Exception $e) {
        return 'FAIL: ' . $e->getMessage();
    } catch (\Throwable $e) {
        return 'ERR(' . get_class($e) . '): ' . $e->getMessage();
    }
}

$cases = [
    // exported (ZEND_EXT_API) global var - expect BOUND
    ['extern void *smm_shared_globals;', 'smm_shared_globals'],
    // hidden global var (ZCSG pointer) - expect FAIL
    ['extern void *accel_shared_globals;', 'accel_shared_globals'],
    // hidden functions - expect FAIL
    ['extern void *zend_shared_alloc(size_t size);', 'zend_shared_alloc'],
    ['extern void zend_shared_alloc_lock(void);', 'zend_shared_alloc_lock'],
    ['extern void zend_accel_shared_protect(bool p);', 'zend_accel_shared_protect'],
    ['extern void *zend_file_cache_script_load(void *fh);', 'zend_file_cache_script_load'],
    ['extern void *zend_accel_hash_update(void *h, void *k, int idx, void *d);', 'zend_accel_hash_update'],
    // exported engine symbol sanity (not opcache) - expect BOUND
    ['extern char zend_system_id[32];', 'zend_system_id'],
];

echo 'opcache enabled: ' . var_export((bool) ini_get('opcache.enable_cli'), true) . "\n";
echo 'opcache loaded: ' . var_export(extension_loaded('Zend OPcache'), true) . "\n\n";
foreach ($cases as [$decl, $name]) {
    printf("%-34s %s\n", $name, tryBind($decl, $name));
}
