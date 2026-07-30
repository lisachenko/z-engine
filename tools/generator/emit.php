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
 *
 * --php-src must point to an extracted php-src tree matching the running PHP
 * (used to slice private structs like zend_closure out of C files).
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

function run(string $command): string
{
    $output   = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    $text = implode("\n", $output);
    if ($exitCode !== 0) {
        fail("Command failed ({$exitCode}): {$command}\n{$text}");
    }

    return $text;
}

/**
 * Runs a command with stdout captured into a file (stderr kept separate so
 * compiler warnings can never corrupt the produced artifact).
 */
function runTo(string $command, string $stdoutFile): void
{
    $errFile  = $stdoutFile . '.err';
    $output   = [];
    $exitCode = 0;
    exec($command . ' > ' . escapeshellarg($stdoutFile) . ' 2> ' . escapeshellarg($errFile), $output, $exitCode);
    if ($exitCode !== 0) {
        $stderr = is_readable($errFile) ? (string) file_get_contents($errFile) : '';
        fail("Command failed ({$exitCode}): {$command}\n{$stderr}");
    }
    @unlink($errFile);
}

$options = getopt('', ['php-src:', 'out:', 'build-dir:', 'only:']);
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

$minor   = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$os      = strtolower(PHP_OS_FAMILY);
$machine = php_uname('m');
$arch    = match ($machine) {
    'x86_64', 'amd64'  => 'x64',
    'aarch64', 'arm64' => 'arm64',
    default            => $machine,
};
$ts          = ZEND_THREAD_SAFE ? 'zts' : 'nts';
$platformKey = "{$minor}/{$os}-{$arch}-{$ts}";

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
$closuresSource = file_get_contents($phpSrc . '/Zend/zend_closures.c');
if ($closuresSource === false) {
    fail("Cannot read {$phpSrc}/Zend/zend_closures.c");
}
if (preg_match('/typedef struct _zend_closure \{.*?\} zend_closure;/s', $closuresSource, $matches) !== 1) {
    fail('Cannot slice struct _zend_closure out of Zend/zend_closures.c');
}
$supplement = "/* Private engine structs sliced from php-src by tools/generator */\n" . $matches[0] . "\n";
file_put_contents($buildDir . '/supplement.h', $supplement);

$includes = trim(run('php-config --includes'));
$index    = null;

// --- 2. Preprocess the engine headers with clang and index the AST ---------
if ($emitHeader) {
    $inputC = <<<'C'
    #include "php.h"
    #include "zend_ast.h"
    #include "zend_language_scanner.h"
    #include "zend_inheritance.h"
    #include "zend_hash.h"
    #include "zend_modules.h"
    #include "zend_arena.h"
    #include "supplement.h"
    C;
    file_put_contents($buildDir . '/input.c', $inputC);
    runTo("clang -E -P -x c {$includes} -I" . escapeshellarg($buildDir) . ' ' . escapeshellarg($buildDir . '/input.c'), $buildDir . '/pre.c');
    runTo('clang -x c -std=c11 -fsyntax-only -Xclang -ast-dump=json ' . escapeshellarg($buildDir . '/pre.c'), $buildDir . '/ast.json');
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
        . "/*\n"
        . " * Generated by tools/generator for PHP {$minor} ({$os}-{$arch}-{$ts}, {$debugFlag}) - DO NOT EDIT.\n"
        . " * Regenerate with `composer gen-headers`.\n"
        . " */\n"
        . $headerBody;
    file_put_contents($buildDir . '/engine.h', $header);

    // The probe source is generated alongside the header (from the same AST) so
    // that a probe-only run on another build of the same PHP minor can reuse it.
    $includeDir  = trim(run('php-config --include-dir'));
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
    run('cc -o ' . escapeshellarg($buildDir . '/probe') . ' ' . escapeshellarg($buildDir . '/probe.c') . " {$includes} -I" . escapeshellarg($buildDir));
    run('cd ' . escapeshellarg($buildDir) . ' && ./probe');
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
        $validation = run(
            PHP_BINARY . ' -d ffi.enable=1 ' . escapeshellarg(__DIR__ . '/validate.php') . ' ' . escapeshellarg($buildDir),
        );
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
