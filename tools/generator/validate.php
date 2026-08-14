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

/**
 * Validation stage: loads the freshly generated engine.h with PHP FFI and
 * asserts that FFI's idea of every struct layout matches the C compiler's
 * ground truth from layouts.json. Run as a subprocess by emit.php so that a
 * parser failure or ABI mismatch fails the build instead of corrupting it.
 *
 * Usage: php -d ffi.enable=1 validate.php <build-dir> [library]
 *
 * The optional library is handed to FFI::cdef() as the module to resolve
 * symbols against. Windows needs it (there is no process-image lookup like
 * RTLD_DEFAULT), and it makes this stage a full symbol check: cdef() resolves
 * EVERY declared function eagerly, including the __vectorcall ones whose
 * lookups FFI mangles into MSVC's decorated name@@N exports. A wrong FFI_LIB
 * or a botched calling convention therefore fails generation right here rather
 * than at the user's first Core::init().
 */

$buildDir = $argv[1] ?? '';
$library  = $argv[2] ?? null;
if (!is_dir($buildDir)) {
    fwrite(STDERR, "validate.php: build dir '{$buildDir}' not found\n");
    exit(1);
}

$header     = file_get_contents($buildDir . '/engine.h');
$layoutsRaw = file_get_contents($buildDir . '/layouts.json');
if ($header === false || $layoutsRaw === false) {
    fwrite(STDERR, "validate.php: missing engine.h or layouts.json\n");
    exit(1);
}
/** @var array{meta: array{php: string, api: int, zts: int, debug: int}, structs: array<string, array{size: int, fields: array<string, int>}>} $layouts */
$layouts = json_decode($layoutsRaw, true, 16, JSON_THROW_ON_ERROR);

$probedMinor  = implode('.', array_slice(explode('.', $layouts['meta']['php']), 0, 2));
$runningMinor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
if ($probedMinor !== $runningMinor) {
    fwrite(STDERR, sprintf(
        "validate.php: layouts were probed on PHP %s but validation runs on PHP %s (minor mismatch)\n",
        $layouts['meta']['php'],
        PHP_VERSION,
    ));
    exit(1);
}
if ((bool) $layouts['meta']['zts'] !== (bool) ZEND_THREAD_SAFE) {
    fwrite(STDERR, "validate.php: thread-safety mismatch between probe and validation PHP\n");
    exit(1);
}

try {
    $ffi = FFI::cdef($header, $library);
} catch (Throwable $error) {
    $problem = $library === null ? 'cannot parse engine.h' : "cannot parse engine.h or resolve its symbols in {$library}";
    fwrite(STDERR, "validate.php: FFI {$problem}: {$error->getMessage()}\n");
    // FFI parse errors carry a line number; print the surrounding header so a
    // CI log alone is enough to diagnose which declaration broke.
    if (preg_match('/at line (\d+)/', $error->getMessage(), $matches) === 1) {
        $lines = explode("\n", $header);
        $line  = (int) $matches[1];
        for ($i = max(0, $line - 6); $i < min(count($lines), $line + 5); $i++) {
            fwrite(STDERR, sprintf("%s %4d | %s\n", $i + 1 === $line ? '>' : ' ', $i + 1, $lines[$i]));
        }
    }
    exit(1);
}

$failures = 0;
foreach ($layouts['structs'] as $struct => $layout) {
    try {
        $type = $ffi->type($struct);
    } catch (Throwable $error) {
        fwrite(STDERR, "validate.php: FFI cannot resolve type {$struct}: {$error->getMessage()}\n");
        $failures++;
        continue;
    }
    $ffiSize = $type->getSize();
    if ($ffiSize !== $layout['size']) {
        fwrite(STDERR, "validate.php: sizeof({$struct}) FFI={$ffiSize} C={$layout['size']}\n");
        $failures++;
    }
    foreach ($layout['fields'] as $field => $expectedOffset) {
        try {
            $ffiOffset = $type->getStructFieldOffset($field);
        } catch (Throwable $error) {
            fwrite(STDERR, "validate.php: FFI cannot resolve {$struct}.{$field}: {$error->getMessage()}\n");
            $failures++;
            continue;
        }
        if ($ffiOffset !== $expectedOffset) {
            fwrite(STDERR, "validate.php: offsetof({$struct}, {$field}) FFI={$ffiOffset} C={$expectedOffset}\n");
            $failures++;
        }
    }
}

if ($failures > 0) {
    fwrite(STDERR, "validate.php: {$failures} layout mismatches - the generated header is WRONG, aborting\n");
    exit(1);
}

echo '[validate] engine.h parsed by FFI' . ($library === null ? '' : " and fully resolved against {$library}")
    . '; ' . count($layouts['structs']) . ' struct layouts match the C compiler exactly';
