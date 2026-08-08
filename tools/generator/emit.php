<?php

/**
 * In-image generator entry point: produces engine.h, constants.php and
 * layouts.json for the PHP build that runs this script.
 *
 * The script MUST run with the exact PHP build the artifacts are meant for
 * (normally inside the official docker image driven by generate.php):
 * everything - struct layouts, constants, thread safety - is extracted from
 * the running build's own headers via clang, a C probe, and FFI.
 *
 * Usage:
 *   php emit.php --php-src=/usr/src/php [--out=DIR] [--build-dir=DIR] [--only=probe|header]
 *                [--include-dir=DIR]
 *
 * --php-src must point to an extracted php-src tree matching the running PHP
 * (used to slice private structs like zend_closure out of C files).
 *
 * --include-dir replaces php-config as the source of the engine headers: the
 * given directory and its main/Zend/TSRM/ext/win32 subdirectories become the
 * include path. Required on Windows, which has no php-config at all - the
 * headers come from the developer pack generate.php downloads.
 *
 * --only=probe  runs only the C probe (constants.php + layouts.json); needs a
 *               C compiler but neither clang nor the FFI extension.
 * --only=header runs only header emission + FFI validation (clang + ext-ffi
 *               required); expects layouts.json already present in --out.
 */

declare(strict_types=1);

namespace ZEngine\Generator;

require __DIR__ . '/lib/ClangAstIndex.php';
require __DIR__ . '/lib/HeaderEmitter.php';
require __DIR__ . '/lib/ProbeGenerator.php';

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

/** @return never */
function fail(string $message): void
{
    fwrite(STDERR, "[generator] ERROR: {$message}\n");
    exit(1);
}

/**
 * Runs a command given as an argv array and returns its standard output
 * (stderr is kept separate and only reported when the command fails, so a
 * compiler warning can never end up inside a parsed result). The array form
 * never goes through a shell, so quoting rules cannot corrupt an argument -
 * on Windows escapeshellarg() mangles % and ! and cmd.exe re-parses whatever
 * quoting it is handed.
 *
 * @param list<string> $command
 */
function run(array $command, ?string $workingDirectory = null): string
{
    $errorFile   = (string) tempnam(sys_get_temp_dir(), 'z-engine-run');
    $descriptors = [1 => ['pipe', 'w'], 2 => ['file', $errorFile, 'w']];
    $process     = proc_open($command, $descriptors, $pipes, $workingDirectory);
    if (!is_resource($process)) {
        fail('Cannot start command: ' . implode(' ', $command));
    }
    $text = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exitCode = proc_close($process);
    $errors   = is_readable($errorFile) ? (string) file_get_contents($errorFile) : '';
    @unlink($errorFile);
    if ($exitCode !== 0) {
        fail('Command failed (' . $exitCode . '): ' . implode(' ', $command) . "\n" . rtrim($text . "\n" . $errors));
    }

    return rtrim($text, "\r\n");
}

/**
 * Runs a command with stdout captured into a file (stderr kept separate so
 * compiler warnings can never corrupt the produced artifact).
 *
 * @param list<string> $command
 */
function runTo(array $command, string $stdoutFile): void
{
    $errFile     = $stdoutFile . '.err';
    $descriptors = [1 => ['file', $stdoutFile, 'w'], 2 => ['file', $errFile, 'w']];
    $process     = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        fail('Cannot start command: ' . implode(' ', $command));
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $stderr = is_readable($errFile) ? (string) file_get_contents($errFile) : '';
        fail('Command failed (' . $exitCode . '): ' . implode(' ', $command) . "\n{$stderr}");
    }
    @unlink($errFile);
}

$options = getopt('', ['php-src:', 'out:', 'build-dir:', 'only:', 'include-dir:']);
$phpSrc  = $options['php-src'] ?? null;
if (!is_string($phpSrc) || !is_dir($phpSrc)) {
    fail('--php-src=DIR pointing to extracted php-src sources is required');
}
$only = $options['only'] ?? '';
if (!in_array($only, ['', 'probe', 'header'], true)) {
    fail('--only accepts "probe" or "header"');
}
$emitHeader = $only !== 'probe';
$runProbe   = $only !== 'header';

$minor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$os    = strtolower(PHP_OS_FAMILY);
// Lower-cased: Windows reports the machine as "AMD64"
$machine = strtolower(php_uname('m'));
$arch    = match ($machine) {
    'x86_64', 'amd64'  => 'x64',
    'aarch64', 'arm64' => 'arm64',
    default            => $machine,
};
$isWindows   = PHP_OS_FAMILY === 'Windows';
$ts          = ZEND_THREAD_SAFE ? 'zts' : 'nts';
$platformKey = "{$minor}/{$os}-{$arch}-{$ts}";

// Windows resolves no symbol out of the process image, so the header has to
// name the engine DLL php.exe already imports; every other platform lets FFI
// search the process itself.
$engineLibrary = 'php' . PHP_MAJOR_VERSION . (ZEND_THREAD_SAFE ? 'ts' : '') . '.dll';

$out      = $options['out']       ?? (dirname(__DIR__, 2) . '/include/' . $platformKey);
$buildDir = $options['build-dir'] ?? (sys_get_temp_dir() . '/z-engine-generator-' . str_replace('/', '-', $platformKey));
assert(is_string($out) && is_string($buildDir));
foreach ([$out, $buildDir] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        fail("Cannot create directory {$directory}");
    }
}

echo '[generator] Target: PHP ' . PHP_VERSION . " ({$platformKey}), debug=" . (PHP_DEBUG ? 'yes' : 'no') . "\n";

/** @var array{types: list<string>, functions: list<string>, variables: list<string>, defines: list<string>, enums: list<string>, opcode_header: string, layout_structs: list<string>, opaque?: list<string>} $manifest */
$manifest = require __DIR__ . '/symbols.php';

// --- 1. Supplement: slice private structs out of php-src C files -----------
/**
 * @param list<string> $patterns
 */
function sliceStructs(string $phpSrc, string $file, array $patterns): string
{
    $source = file_get_contents($phpSrc . '/' . $file);
    if ($source === false) {
        fail("Cannot read {$phpSrc}/{$file}");
    }
    $slices = [];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $source, $matches) !== 1) {
            fail("Cannot slice {$pattern} out of {$file}");
        }
        $slices[] = $matches[0];
    }

    return implode("\n\n", $slices);
}

$supplement = "/* Private engine structs sliced from php-src by tools/generator */\n"
    . sliceStructs($phpSrc, 'Zend/zend_closures.c', [
        '/typedef struct _zend_closure \{.*?\} zend_closure;/s',
    ])
    . "\n\n"
    // Opcache's private structs live in ext/opcache (not installed by php-dev):
    // accel_time_t (whichever of the two #ifdef ZEND_WIN32 branches this build
    // compiles), the early-binding record and the persistent script container
    // from ZendAccelerator.h, and the file-cache header record from
    // zend_file_cache.c.
    . sliceStructs($phpSrc, 'ext/opcache/ZendAccelerator.h', [
        $isWindows ? '/typedef unsigned __int64 accel_time_t;/' : '/typedef time_t accel_time_t;/',
        '/typedef struct _zend_early_binding \{.*?\} zend_early_binding;/s',
        '/typedef struct _zend_persistent_script \{.*?\} zend_persistent_script;/s',
    ])
    . "\n\n"
    . sliceStructs($phpSrc, 'ext/opcache/zend_file_cache.c', [
        '/typedef struct _zend_file_cache_metainfo \{.*?\} zend_file_cache_metainfo;/s',
    ])
    . "\n";
if ($isWindows) {
    // MSVC's __int64 spelling is not parseable by PHP FFI; long long is the
    // very same 64-bit type for the probe compiler and for FFI alike.
    $supplement = (string) preg_replace('/\b__int64\b/', 'long long', $supplement);
}
file_put_contents($buildDir . '/supplement.h', $supplement);

// Engine headers: php-config knows them everywhere except on Windows, where
// --include-dir points at the developer pack unpacked by generate.php.
$includeDirOption = $options['include-dir'] ?? null;
if (is_string($includeDirOption)) {
    if (!is_dir($includeDirOption)) {
        fail("--include-dir={$includeDirOption} is not a directory");
    }
    $includeDir = rtrim(str_replace('\\', '/', $includeDirOption), '/');
    $includes   = ['-I' . $includeDir];
    foreach (['main', 'Zend', 'TSRM', 'ext', 'win32'] as $subdirectory) {
        if (is_dir("{$includeDir}/{$subdirectory}")) {
            $includes[] = "-I{$includeDir}/{$subdirectory}";
        }
    }
} else {
    $includes   = preg_split('/\s+/', trim(run(['php-config', '--includes'])), -1, PREG_SPLIT_NO_EMPTY);
    $includeDir = trim(run(['php-config', '--include-dir']));
    assert(is_array($includes));
}

// Windows configuration macros come from the build's CFLAGS, not from any
// installed header: without them Zend/zend_config.w32.h is never selected and
// the whole engine parses as if it were POSIX.
$defines = [];
if ($isWindows) {
    $defines = ['-DZEND_WIN32=1', '-DPHP_WIN32=1', '-DWIN32', '-D_MBCS'];
    if (ZEND_THREAD_SAFE) {
        $defines[] = '-DZTS=1';
    }
}

$index = null;

// --- 2. Preprocess the engine headers with clang and index the AST ---------
if ($emitHeader) {
    $inputC = <<<'C'
    #include "php.h"
    #include "zend_ast.h"
    #include "zend_attributes.h"
    #include "zend_language_scanner.h"
    #include "zend_inheritance.h"
    #include "zend_hash.h"
    #include "zend_modules.h"
    #include "zend_arena.h"
    #include "zend_exceptions.h"
    #include "zend_system_id.h"
    #include "Optimizer/zend_optimizer.h"
    #include "supplement.h"
    C;
    file_put_contents($buildDir . '/input.c', $inputC);
    runTo(
        ['clang', '-E', '-P', '-x', 'c', ...$includes, '-I' . $buildDir, ...$defines, $buildDir . '/input.c'],
        $buildDir . '/pre.c',
    );
    runTo(
        ['clang', '-x', 'c', '-std=c11', '-fsyntax-only', '-Xclang', '-ast-dump=json', ...$defines, $buildDir . '/pre.c'],
        $buildDir . '/ast.json',
    );
    $index = ClangAstIndex::fromFiles($buildDir . '/pre.c', $buildDir . '/ast.json');
    echo "[generator] Preprocessed and parsed engine headers\n";
}

// --- 3. Emit engine.h ------------------------------------------------------
if ($emitHeader) {
    assert($index !== null);
    $emitter = new HeaderEmitter(
        $index,
        $manifest['types'],
        $manifest['functions'],
        $manifest['variables'],
        $manifest['opaque'] ?? [],
    );
    $headerBody = $emitter->emit();
    $debugFlag  = PHP_DEBUG ? 'debug' : 'release';
    $header     = "#define FFI_SCOPE \"ZEngine\"\n"
        // Windows only: FFI::load() needs a library to resolve symbols against
        // (there is no equivalent of RTLD_DEFAULT there). Core::init() uses
        // FFI::cdef(), which ignores FFI_LIB, and passes the same name itself.
        . ($isWindows ? "#define FFI_LIB \"{$engineLibrary}\"\n" : '')
        . "/*\n"
        . " * Generated by tools/generator for PHP {$minor} ({$os}-{$arch}-{$ts}, {$debugFlag}) - DO NOT EDIT.\n"
        . " * Regenerate with `composer gen-headers`.\n"
        . " */\n"
        . $headerBody;
    file_put_contents($buildDir . '/engine.h', $header);

    // The probe source is generated alongside the header (from the same AST) so
    // that a probe-only run on another build of the same PHP minor can reuse it.
    $opcodes     = ProbeGenerator::parseOpcodes($includeDir . '/' . $manifest['opcode_header']);
    $enumMembers = [];
    foreach ($manifest['enums'] as $enumTag) {
        $members = $index->enumMemberNames($enumTag);
        if ($members === []) {
            fail("Manifest enum '{$enumTag}' has no members in the engine headers");
        }
        $enumMembers[$enumTag] = $members;
    }
    $layoutFields  = [];
    $layoutIsUnion = [];
    foreach ($manifest['layout_structs'] as $structName) {
        $isUnion = $index->isUnion($structName);
        $fields  = $isUnion ? [] : $index->structFieldNames($structName);
        if (!$isUnion && $fields === []) {
            fail("Layout struct '{$structName}' has no fields in the engine headers");
        }
        $layoutFields[$structName]  = $fields;
        $layoutIsUnion[$structName] = $isUnion;
    }
    $probe = new ProbeGenerator($manifest['defines'], $enumMembers, $opcodes, $layoutFields, $layoutIsUnion);
    file_put_contents($buildDir . '/probe.c', $probe->generateProbeSource($buildDir . '/supplement.h'));
    echo '[generator] Emitted engine.h (' . strlen($header) . " bytes) and probe.c\n";
}

// --- 4. Compile and run the probe: constants.php + layouts.json ------------
if ($runProbe) {
    if (!is_file($buildDir . '/probe.c')) {
        fail('probe.c not found in build dir; run a header pass first (it generates probe.c from the clang AST)');
    }
    // Windows has no cc: clang (the very compiler that parsed the headers)
    // builds the probe there, and the executable needs the .exe suffix.
    $compiler  = $isWindows ? 'clang' : 'cc';
    $probeFile = $buildDir . ($isWindows ? '/probe.exe' : '/probe');
    run([$compiler, '-o', $probeFile, $buildDir . '/probe.c', ...$includes, '-I' . $buildDir, ...$defines]);
    // The probe writes constants.php and layouts.json into its working
    // directory; Windows resolves a bare name against PATH, not against it.
    run([$isWindows ? $probeFile : './probe'], $buildDir);
    $layoutsRaw = file_get_contents($buildDir . '/layouts.json');
    if ($layoutsRaw === false) {
        fail('Probe did not produce layouts.json');
    }
    $layouts = json_decode($layoutsRaw, true, 16, JSON_THROW_ON_ERROR);
    file_put_contents(
        $buildDir . '/layouts.json',
        json_encode($layouts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
    echo "[generator] Probe extracted constants and layouts\n";
} elseif (is_file($out . '/layouts.json')) {
    // Header-only pass: validate against previously probed ground truth
    copy($out . '/layouts.json', $buildDir . '/layouts.json');
} else {
    fail('--only=header requires layouts.json in the output directory (run --only=probe first)');
}

// --- 5. Validate: FFI must load the header and agree with the C compiler ---
if ($emitHeader) {
    if (!extension_loaded('ffi')) {
        echo "[generator] WARNING: ext-ffi not available, skipping FFI validation\n";
    } else {
        // On Windows the engine DLL is handed over as well: FFI::cdef() then
        // resolves every declared function through it right away, so a wrong
        // FFI_LIB or a botched __vectorcall decoration fails the build here.
        $validation = run([
            PHP_BINARY, '-d', 'ffi.enable=1', __DIR__ . '/validate.php', $buildDir,
            ...($isWindows ? [$engineLibrary] : []),
        ]);
        echo $validation . "\n";
    }
}

// --- 6. Publish artifacts --------------------------------------------------
$artifacts = [];
if ($emitHeader) {
    $artifacts[] = 'engine.h';
    $artifacts[] = 'probe.c';
}
if ($runProbe) {
    $artifacts[] = 'constants.php';
    $artifacts[] = 'layouts.json';
}
foreach ($artifacts as $artifact) {
    if (!copy($buildDir . '/' . $artifact, $out . '/' . $artifact)) {
        fail("Cannot copy {$artifact} to {$out}");
    }
}
echo '[generator] Published ' . implode(', ', $artifacts) . " to {$out}\n";
