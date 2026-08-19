<?php

// SPIKE #121 (throwaway research, docs/research/zcsg-spike.md) - NOT wired into src/. Do not ship.
// SPIKE: reach ZCSG (accel_shared_globals) through the EXPORTED smm_shared_globals->app_shared_globals
declare(strict_types=1);

$ffi = FFI::cdef(<<<'C'
typedef struct _zend_shared_segment {
    size_t size; size_t end; size_t pos; void *p;
} zend_shared_segment;
typedef struct _zend_shared_memory_state {
    size_t *positions; size_t shared_free;
} zend_shared_memory_state;
typedef struct _zend_smm_shared_globals {
    zend_shared_segment **shared_segments;
    int shared_segments_count;
    size_t shared_free;
    size_t wasted_shared_memory;
    _Bool memory_exhausted;
    zend_shared_memory_state shared_memory_state;
    void *app_shared_globals;
    void *reserved;
    size_t reserved_size;
} zend_smm_shared_globals;
extern zend_smm_shared_globals *smm_shared_globals;
C);

$g = $ffi->smm_shared_globals;
if (FFI::isNull($g)) {
    echo "smm_shared_globals is NULL (opcache SHM not initialised)\n";
    exit(1);
}
printf("smm_shared_globals            = %s\n", var_export(FFI::isNull($g) ? null : 'ptr', true));
printf("shared_segments_count         = %d\n", $g->shared_segments_count);
printf("shared_free                   = %d bytes\n", $g->shared_free);
printf("wasted_shared_memory          = %d\n", $g->wasted_shared_memory);
printf("memory_exhausted              = %d\n", $g->memory_exhausted);
$app = $g->app_shared_globals;
printf("app_shared_globals (== ZCSG)  = %s\n", FFI::isNull($app) ? 'NULL' : 'NON-NULL -> ZCSG base recoverable');
// segment 0 base+size -> the range we'd need to mprotect for opcache.protect_memory
for ($i = 0; $i < $g->shared_segments_count; $i++) {
    $seg = $g->shared_segments[$i];
    printf(
        "  segment[%d]: p=%s size=%d pos=%d end=%d\n",
        $i,
        FFI::isNull($seg->p) ? 'NULL' : sprintf('0x%x', (int) FFI::cast('uintptr_t', $seg->p)->cdata),
        $seg->size,
        $seg->pos,
        $seg->end,
    );
}
