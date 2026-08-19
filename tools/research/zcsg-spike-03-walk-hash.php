<?php

// SPIKE #121 (throwaway research, docs/research/zcsg-spike.md) - NOT wired into src/. Do not ship.
declare(strict_types=1);
$ffi = FFI::cdef(<<<'C'
typedef struct _smm {
    void *shared_segments; int shared_segments_count; size_t shared_free;
    size_t wasted; _Bool exhausted;
    struct { size_t *positions; size_t shared_free; } state;
    void *app_shared_globals; void *reserved; size_t reserved_size;
} smm_t;
extern smm_t *smm_shared_globals;

typedef struct _hentry {
    uint64_t hash_value; void *key; struct _hentry *next; void *data; _Bool indirect;
} hentry;
typedef struct _ahash { hentry **hash_table; hentry *hash_entries;
    uint32_t num_entries; uint32_t max_num_entries; uint32_t num_direct_entries; } ahash;
typedef struct _zcsg {
    uint64_t hits,misses,blacklist_misses,oom_restarts,hash_restarts,manual_restarts;
    ahash hash;
} zcsg;
typedef struct { uint32_t refcount; uint32_t type_info; uint64_t h; size_t len; char val[1]; } zstr;
C);

$smm = $ffi->smm_shared_globals;
if (FFI::isNull($smm) || FFI::isNull($smm->app_shared_globals)) {
    fwrite(STDERR, "no SHM/ZCSG\n");
    exit(2);
}
$z = $ffi->cast('zcsg*', $smm->app_shared_globals);
$h = FFI::addr($z->hash);
printf("ZCSG(hash): num_entries=%d max=%d direct=%d\n", $h->num_entries, $h->max_num_entries, $h->num_direct_entries);

$readZstr = function ($p) use ($ffi) {
    if (FFI::isNull($p)) {
        return '(null)';
    }
    $s   = $ffi->cast('zstr*', $p);
    $len = (int) $s->len;
    if ($len <= 0 || $len > 4096) {
        return "(len=$len)";
    }
    return FFI::string(FFI::addr($s->val), $len);
};
$n = min((int) $h->num_direct_entries, 50);
for ($i = 0; $i < $n; $i++) {
    $e = $h->hash_entries[$i];
    printf(
        "  [%2d] indirect=%d data=%s key=%s\n",
        $i,
        (int) $e->indirect,
        FFI::isNull($e->data) ? 'NULL' : 'ptr',
        $readZstr($e->key),
    );
}
echo "OK: enumerated SHM-resident scripts from userland (READ path proven)\n";
