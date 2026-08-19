<?php

// SPIKE #121 (throwaway research, docs/research/zcsg-spike.md) - NOT wired into src/. Do not ship.
// SPIKE: is the SHM segment writable from userland? demonstrates opcache.protect_memory hazard.
declare(strict_types=1);
$ffi = FFI::cdef(<<<'C'
typedef struct _seg { size_t size; size_t end; size_t pos; void *p; } seg;
typedef struct _smm {
    seg **shared_segments; int shared_segments_count; size_t shared_free;
    size_t wasted; _Bool exhausted;
    struct { size_t *positions; size_t shared_free; } state;
    void *app_shared_globals; void *reserved; size_t reserved_size;
} smm_t;
extern smm_t *smm_shared_globals;
C);
$smm = $ffi->smm_shared_globals;
$seg = $smm->shared_segments[0];
// write to a free byte well past pos (unused stack space) - benign
$target = $ffi->cast('char*', $seg->p);
$off    = (int) $seg->pos + 4096; // in free region, page-aligned-ish
printf(
    "protect_memory=%s seg.p=0x%x pos=%d writing at +%d\n",
    ini_get('opcache.protect_memory'),
    (int) $ffi->cast('uintptr_t', $seg->p)->cdata,
    $seg->pos,
    $off,
);
$target[$off] = "\x5a";
echo "WRITE SUCCEEDED (SHM is userland-writable in this config)\n";
