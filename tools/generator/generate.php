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
 * Host-side generator driver: builds the generator docker image for every
 * requested (PHP minor, thread-safety) combination and exports the generated
 * FFI artifacts into include/{minor}/{os}-{arch}-{ts}/.
 *
 * Usage:
 *   php tools/generator/generate.php               # all targets of this branch
 *   php tools/generator/generate.php --php=8.4 --ts=nts
 *   php tools/generator/generate.php --native [--php-src=DIR] [--php-dev=DIR]
 *
 * Requires docker (with buildx). Invoked via `composer gen-headers`.
 *
 * Native mode (--native, auto-selected on non-Linux hosts) skips docker and
 * runs emit.php directly against the running PHP build - the only way to
 * generate darwin-* and windows-* artifacts, since docker containers are Linux
 * by construction. It generates for the running interpreter only and fetches
 * the three php-src files emit.php slices unless --php-src points to a tree.
 *
 * On Windows there is no php-config and no php-dev package: the engine headers
 * come from the official developer pack, which native mode downloads and
 * caches in the temp directory. --php-dev=DIR (or Z_ENGINE_PHP_DEVPACK) points
 * at an already extracted one instead.
 */

error_reporting(E_ALL);

/**
 * Version/TS targets maintained on this branch. Extend when a new platform
 * or thread-safety mode becomes supported (see AGENTS.md).
 *
 * @var list<array{php: string, ts: string}> $defaultTargets
 */
$defaultTargets = [
    ['php' => '8.4', 'ts' => 'nts'],
    ['php' => '8.4', 'ts' => 'zts'],
];

/** @return never */
function abortWith(string $message): void
{
    fwrite(STDERR, "==> ERROR: {$message}\n");
    exit(1);
}

/**
 * Spawns a command given as an argv array and returns its exit code. The array
 * form never reaches a shell, which is the only portable way to spawn anything
 * on Windows: escapeshellarg() mutilates % and ! there, and cmd.exe re-parses
 * the quoting of whatever it is handed. Stdio is inherited unless $quiet is
 * set, which swallows the (tiny) output of the tool-availability probes.
 *
 * @param list<string> $command
 */
function runCommand(array $command, bool $quiet = false): int
{
    $descriptors = $quiet ? [1 => ['pipe', 'w'], 2 => ['pipe', 'w']] : [];
    $process     = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        return 127;
    }
    foreach ($pipes as $pipe) {
        stream_get_contents($pipe);
        fclose($pipe);
    }

    return proc_close($process);
}

/**
 * Intercepting TLS proxies: point curl at the CA bundle the host advertises
 * unless the environment already configured one.
 */
function applyCurlCaBundle(): void
{
    $caBundle = getenv('Z_ENGINE_BUILD_CA') ?: (getenv('NODE_EXTRA_CA_CERTS') ?: '');
    if (is_string($caBundle) && $caBundle !== '' && is_readable($caBundle) && getenv('CURL_CA_BUNDLE') === false) {
        putenv("CURL_CA_BUNDLE={$caBundle}");
    }
}

function downloadFile(string $url, string $destination): int
{
    return runCommand(['curl', '-fsSL', '--retry', '3', '-o', $destination, $url]);
}

/**
 * The PHP version a developer pack provides, or null when the directory is not
 * one (main/php_version.h is the pack's own version stamp).
 */
function developmentPackVersion(string $directory): ?string
{
    $header = $directory . '/include/main/php_version.h';
    if (!is_file($header)) {
        return null;
    }
    $contents = (string) file_get_contents($header);

    return preg_match('/#\s*define\s+PHP_VERSION\s+"([^"]+)"/', $contents, $matches) === 1 ? $matches[1] : null;
}

/**
 * Publishes the transient stub artifacts (structs.php and phpstorm.meta.php,
 * emitted next to engine.h) for the CANONICAL target only.
 *
 * The stubs are an analysis-only, branch-level artifact: their field *types*
 * are IDE/PHPStan hints derived from one canonical build (linux-x64-nts), not
 * a per-platform ABI record - that is layouts.json's job, and it stays
 * per-target. A field's C spelling can genuinely differ across platforms
 * (`zend_atomic_bool.value` is `_Atomic(_Bool)` on linux/darwin but
 * `volatile char` on windows) without changing what the PHP code, which is
 * platform-agnostic, may read. So the canonical build is authoritative and
 * the other targets neither publish nor byte-compare the stubs; they just
 * discard their transient copies so include/<target>/ keeps only its four
 * per-target artifacts. Staleness of the committed stubs against the canonical
 * engine is caught by the linux header-drift job (which diffs stubs/ too).
 */
function publishStubs(string $outputDir, string $repositoryRoot, bool $isCanonical): void
{
    $destinations = [
        'structs.php'       => "{$repositoryRoot}/stubs/zend-engine-structs.php",
        'phpstorm.meta.php' => "{$repositoryRoot}/.phpstorm.meta.php",
    ];
    foreach ($destinations as $transient => $committed) {
        $generated = "{$outputDir}/{$transient}";
        if (!is_file($generated)) {
            abortWith("the generator did not produce {$transient} in {$outputDir}");
        }
        if (!$isCanonical) {
            // Non-canonical target: the stubs are owned by the canonical build; drop the
            // transient copy so include/<target>/ stays clean, without publishing or diffing.
            unlink($generated);
            continue;
        }
        if (!is_dir(dirname($committed)) && !mkdir(dirname($committed), 0777, true)) {
            abortWith('cannot create ' . dirname($committed));
        }
        if (!rename($generated, $committed)) {
            abortWith("cannot move {$generated} to {$committed}");
        }
    }
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        assert($entry instanceof SplFileInfo);
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($directory);
}

/**
 * Resolves the PHP developer pack that carries the engine headers on Windows
 * (which has neither php-config nor a php-dev package) and returns its root
 * directory - the one holding include/. Downloaded packs are cached per
 * version and thread-safety mode in the temp directory.
 */
function windowsDevelopmentPack(?string $override, string $ts): string
{
    if ($override !== null && $override !== '') {
        $override = rtrim(str_replace('\\', '/', $override), '/');
        if (!is_dir($override . '/include')) {
            abortWith("{$override} is not a PHP developer pack (no include/ subdirectory)");
        }

        return $override;
    }

    $cacheDir = sys_get_temp_dir() . '/z-engine-php-devpack-' . PHP_VERSION . '-' . $ts;
    if (developmentPackVersion($cacheDir) === PHP_VERSION) {
        echo "==> Using cached PHP developer pack {$cacheDir}\n";

        return $cacheDir;
    }
    if (!class_exists(ZipArchive::class)) {
        abortWith('unpacking the PHP developer pack needs ext-zip; pass --php-dev=DIR with an extracted one instead');
    }
    applyCurlCaBundle();

    $temporary = $cacheDir . '-download';
    removeDirectory($temporary);
    if (!mkdir($temporary, 0777, true) && !is_dir($temporary)) {
        abortWith("cannot create {$temporary}");
    }

    // windows.php.net publishes the current patch release of every branch
    // together with the sha256 of each downloadable, the developer pack
    // included. ZTS is the UNMARKED build name ("ts-vsNN-x64"), NTS spells
    // itself out, and the Visual Studio toolset moves with every new compiler
    // generation - so the key is matched, never assumed.
    $releasesUrl  = 'https://windows.php.net/downloads/releases/releases.json';
    $releasesFile = $temporary . '/releases.json';
    if (downloadFile($releasesUrl, $releasesFile) !== 0) {
        abortWith("cannot download {$releasesUrl}; pass --php-dev=DIR with an extracted developer pack");
    }
    $releases = json_decode((string) file_get_contents($releasesFile), true);
    $minor    = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $release  = is_array($releases) && is_array($releases[$minor] ?? null) ? $releases[$minor] : [];

    $wantedPrefix    = $ts === 'zts' ? 'ts' : 'nts';
    $vsTag           = '';
    $developmentPack = [];
    foreach ($release as $key => $build) {
        $isBuildKey = preg_match('/^(nts|ts)-(vs\d+)-x64$/', (string) $key, $matches) === 1;
        if (!$isBuildKey || $matches[1] !== $wantedPrefix) {
            continue;
        }
        $vsTag           = $matches[2];
        $developmentPack = is_array($build) && is_array($build['devel_pack'] ?? null) ? $build['devel_pack'] : [];
        break;
    }
    if ($vsTag === '') {
        abortWith("windows.php.net lists no {$wantedPrefix}-vs*-x64 build for PHP {$minor}; "
            . 'pass --php-dev=DIR with an extracted developer pack');
    }

    $currentVersion = is_string($release['version'] ?? null) ? $release['version'] : '';
    $expectedHash   = '';
    if ($currentVersion === PHP_VERSION && is_string($developmentPack['path'] ?? null)) {
        $packUrl      = 'https://windows.php.net/downloads/releases/' . $developmentPack['path'];
        $expectedHash = is_string($developmentPack['sha256'] ?? null) ? $developmentPack['sha256'] : '';
    } else {
        // Once a newer patch release ships, the previous one moves into the
        // archives - which releases.json does not describe at all, so the file
        // name is derived from the running version and the matched toolset
        // (and comes without a published checksum).
        $packUrl = 'https://windows.php.net/downloads/releases/archives/php-devel-pack-' . PHP_VERSION
            . ($ts === 'zts' ? '' : '-nts') . "-Win32-{$vsTag}-x64.zip";
    }

    $packFile = $temporary . '/devel-pack.zip';
    echo "==> Downloading {$packUrl}\n";
    if (downloadFile($packUrl, $packFile) !== 0) {
        abortWith("cannot download {$packUrl}; pass --php-dev=DIR with a developer pack for PHP " . PHP_VERSION);
    }
    if ($expectedHash !== '') {
        $actualHash = (string) hash_file('sha256', $packFile);
        if (!hash_equals(strtolower($expectedHash), strtolower($actualHash))) {
            abortWith("sha256 mismatch for {$packUrl}: expected {$expectedHash}, got {$actualHash}");
        }
    }

    $archive = new ZipArchive();
    if ($archive->open($packFile) !== true) {
        abortWith("cannot open the downloaded developer pack {$packFile}");
    }
    $unpacked = $temporary . '/unpacked';
    if (!$archive->extractTo($unpacked)) {
        abortWith("cannot extract {$packFile} into {$unpacked}");
    }
    $archive->close();

    $roots = glob($unpacked . '/php-*-devel-*-x64');
    if (!is_array($roots) || count($roots) !== 1) {
        abortWith("unexpected developer pack layout in {$unpacked} (no single php-*-devel-*-x64 directory)");
    }
    removeDirectory($cacheDir);
    if (!rename($roots[0], $cacheDir)) {
        abortWith("cannot move {$roots[0]} to {$cacheDir}");
    }
    removeDirectory($temporary);

    $packVersion = developmentPackVersion($cacheDir);
    if ($packVersion !== PHP_VERSION) {
        removeDirectory($cacheDir);
        abortWith("the developer pack from {$packUrl} is for PHP " . ($packVersion ?? 'an unknown version')
            . ' but the running PHP is ' . PHP_VERSION . ' - headers must match the running build exactly; '
            . 'pass --php-dev=DIR with a matching developer pack');
    }
    echo "==> Extracted PHP developer pack to {$cacheDir}\n";

    return $cacheDir;
}

$options = getopt('', ['php:', 'ts:', 'native', 'php-src:', 'php-dev:']);
$targets = $defaultTargets;
if (isset($options['php']) || isset($options['ts'])) {
    $php     = is_string($options['php'] ?? null) ? $options['php'] : '8.4';
    $ts      = is_string($options['ts'] ?? null) ? $options['ts'] : 'nts';
    $targets = [['php' => $php, 'ts' => $ts]];
}

$repositoryRoot = dirname(__DIR__, 2);
// Lower-cased: Windows reports the machine as "AMD64"
$machine = strtolower(php_uname('m'));
$arch    = match ($machine) {
    'x86_64', 'amd64'  => 'x64',
    'aarch64', 'arm64' => 'arm64',
    default            => $machine,
};

// ---------------------------------------------------------------------------
// Native (non-docker) mode: run emit.php directly against the running PHP
// build. Auto-selected off Linux, where docker cannot help anyway: containers
// are Linux by construction, so they would emit linux-* artifacts.
// ---------------------------------------------------------------------------
$isNative = isset($options['native']) || PHP_OS_FAMILY !== 'Linux';
if ($isNative) {
    $runningMinor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $runningTs    = ZEND_THREAD_SAFE ? 'zts' : 'nts';
    $platform     = strtolower(PHP_OS_FAMILY) . "-{$arch}-{$runningTs}";

    // Everything - layouts, constants, thread safety - comes from the build
    // that runs emit.php, so native mode can only generate for it: an explicit
    // --php/--ts that disagrees is an error, the implicit default target list
    // just collapses to the running build.
    if ((isset($options['php']) || isset($options['ts']))
        && ($targets[0]['php'] !== $runningMinor || $targets[0]['ts'] !== $runningTs)
    ) {
        fwrite(STDERR, '==> ERROR: native mode generates for the running interpreter only '
            . "(PHP {$runningMinor} {$runningTs}); requested {$targets[0]['php']} {$targets[0]['ts']}. "
            . "Run under a matching PHP build instead.\n");
        exit(1);
    }

    $isWindows  = PHP_OS_FAMILY === 'Windows';
    $needsFetch = !isset($options['php-src']);
    $missing    = [];
    if ($isWindows) {
        // Windows has neither a POSIX shell nor php-config nor cc: clang does
        // every compilation step (it is what parses the headers anyway) and
        // curl - shipped with Windows itself - fetches the php-src slices and
        // the developer pack. `where` is the lookup tool.
        foreach (['clang', 'curl'] as $tool) {
            if (runCommand(['where', $tool], true) !== 0) {
                $missing[] = $tool;
            }
        }
    } else {
        foreach (array_merge(['php-config', 'clang', 'cc'], $needsFetch ? ['curl'] : []) as $tool) {
            exec('command -v ' . escapeshellarg($tool) . ' >/dev/null 2>&1', $ignored, $exitCode);
            if ($exitCode !== 0) {
                $missing[] = $tool;
            }
        }
    }
    if (!extension_loaded('ffi')) {
        $missing[] = 'ext-ffi (the running php must have the FFI extension)';
    }
    if ($missing !== []) {
        fwrite(STDERR, '==> ERROR: native mode needs the following on this host: ' . implode(', ', $missing) . "\n");
        exit(1);
    }
    if (!$isWindows) {
        $phpConfigVersion = trim((string) shell_exec('php-config --version 2>/dev/null'));
        if ($phpConfigVersion !== PHP_VERSION) {
            fwrite(STDERR, "==> ERROR: php-config reports {$phpConfigVersion} but the running PHP is "
                . PHP_VERSION . " - dev headers must match the running build exactly.\n");
            exit(1);
        }
    }

    // emit.php only needs the php-src tree to slice three private-struct
    // files; fetching exactly those for the running patch release is
    // equivalent to a full checkout (see AGENTS.md).
    $phpSrc = is_string($options['php-src'] ?? null) ? $options['php-src'] : '';
    if ($phpSrc === '') {
        $phpSrc = sys_get_temp_dir() . '/z-engine-php-src-' . PHP_VERSION;
        applyCurlCaBundle();
        foreach (['Zend/zend_closures.c', 'ext/opcache/ZendAccelerator.h', 'ext/opcache/zend_file_cache.c'] as $file) {
            $destination = "{$phpSrc}/{$file}";
            if (is_file($destination)) {
                continue;
            }
            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0777, true);
            }
            $url      = 'https://raw.githubusercontent.com/php/php-src/php-' . PHP_VERSION . "/{$file}";
            $exitCode = downloadFile($url, $destination);
            if ($exitCode !== 0) {
                fwrite(STDERR, "==> ERROR: could not fetch {$url} (exit {$exitCode}). If this PHP build "
                    . "has no matching php-src tag, pass --php-src=DIR with a matching tree.\n");
                exit(1);
            }
        }
    } elseif (!is_dir($phpSrc)) {
        fwrite(STDERR, "==> ERROR: --php-src={$phpSrc} is not a directory\n");
        exit(1);
    }

    // Windows resolves the engine headers out of the developer pack instead of
    // php-config, which does not exist there at all.
    $command = [PHP_BINARY, '-d', 'memory_limit=2G', __DIR__ . '/emit.php', '--php-src=' . $phpSrc];
    if ($isWindows) {
        $developmentPackOption = $options['php-dev'] ?? null;
        if (!is_string($developmentPackOption)) {
            $environmentPack       = getenv('Z_ENGINE_PHP_DEVPACK');
            $developmentPackOption = is_string($environmentPack) && $environmentPack !== '' ? $environmentPack : null;
        }
        $developmentPack = windowsDevelopmentPack($developmentPackOption, $runningTs);
        $command[]       = '--include-dir=' . $developmentPack . '/include';
    }

    echo "==> Generating {$runningMinor} {$platform} natively from PHP " . PHP_VERSION . "\n";
    $exitCode = runCommand($command);
    if ($exitCode !== 0) {
        fwrite(STDERR, "==> FAILED: {$runningMinor} {$runningTs} (exit {$exitCode})\n");
        exit(1);
    }
    publishStubs(
        "{$repositoryRoot}/include/{$runningMinor}/{$platform}",
        $repositoryRoot,
        PHP_OS_FAMILY === 'Linux' && $arch === 'x64' && $runningTs === 'nts',
    );
    echo "==> OK: {$repositoryRoot}/include/{$runningMinor}/{$platform}\n";
    exit(0);
}

// Forward proxy settings into the build when the host uses them. Proxied
// environments also need host networking so build containers can reach a
// loopback proxy.
$proxyArguments = '';
$usesProxy      = false;
foreach (['HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY', 'http_proxy', 'https_proxy', 'no_proxy'] as $variable) {
    $value = getenv($variable);
    if (is_string($value) && $value !== '') {
        $proxyArguments .= ' --build-arg ' . escapeshellarg("{$variable}={$value}");
        $usesProxy = true;
    }
}
if ($usesProxy) {
    $proxyArguments .= ' --network host';
}

// Optional registry mirror prefix for docker-hub-restricted environments,
// e.g. Z_ENGINE_DOCKER_MIRROR="mirror.gcr.io/library/"
$mirror = getenv('Z_ENGINE_DOCKER_MIRROR');
$mirror = is_string($mirror) ? $mirror : '';

// When the host goes through an intercepting TLS proxy, drop its CA bundle
// into the build context so image layers can trust it (cleaned up afterwards).
$caBundle       = getenv('Z_ENGINE_BUILD_CA') ?: (getenv('NODE_EXTRA_CA_CERTS') ?: '');
$caBundleInPath = __DIR__ . '/ca-bundle.crt';
$caBundleCopied = false;
if ($usesProxy && is_string($caBundle) && $caBundle !== '' && is_readable($caBundle)) {
    copy($caBundle, $caBundleInPath);
    $caBundleCopied = true;
}

// Optional buildx layer-cache directory (e.g. wrapped in actions/cache by CI):
// per-target subdirectories, rotated after each successful build so the local
// cache never accumulates stale layers across runs.
$layerCacheDir = getenv('Z_ENGINE_BUILDX_CACHE_DIR');
$layerCacheDir = is_string($layerCacheDir) && $layerCacheDir !== '' ? rtrim($layerCacheDir, '/') : '';

// Z_ENGINE_BUILDX_CACHE_READONLY=1 reads the cache without writing it back.
// Exporting the layers again costs real time (13-15s per target) and is pure
// waste when the caller already knows the cache it restored is current and will
// not be storing a new copy - which is exactly the case on an actions/cache hit,
// since its entries are immutable.
$layerCacheReadOnly = getenv('Z_ENGINE_BUILDX_CACHE_READONLY');
$layerCacheReadOnly = is_string($layerCacheReadOnly) && $layerCacheReadOnly !== '' && $layerCacheReadOnly !== '0';

$failures = 0;
foreach ($targets as $target) {
    $baseImage = $mirror . ($target['ts'] === 'zts' ? "php:{$target['php']}-zts" : "php:{$target['php']}-cli");
    $outputDir = "{$repositoryRoot}/include/{$target['php']}/linux-{$arch}-{$target['ts']}";

    $cacheArguments = '';
    $targetCacheDir = '';
    if ($layerCacheDir !== '') {
        $targetCacheDir = "{$layerCacheDir}/{$target['php']}-{$target['ts']}";
        if (is_dir($targetCacheDir)) {
            $cacheArguments .= ' --cache-from ' . escapeshellarg("type=local,src={$targetCacheDir}");
        }
        if (!$layerCacheReadOnly) {
            $cacheArguments .= ' --cache-to ' . escapeshellarg("type=local,dest={$targetCacheDir}-new,mode=max");
        }
    }

    $command = 'docker buildx build --progress=plain'
        . ' --build-arg ' . escapeshellarg("BASE_IMAGE={$baseImage}")
        . $proxyArguments
        . $cacheArguments
        . ' --output ' . escapeshellarg("type=local,dest={$outputDir}")
        . ' ' . escapeshellarg($repositoryRoot . '/tools/generator');

    echo "==> Generating {$target['php']} linux-{$arch}-{$target['ts']} from {$baseImage}\n";
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, "==> FAILED: {$target['php']} {$target['ts']} (exit {$exitCode})\n");
        $failures++;
        continue;
    }
    if ($targetCacheDir !== '' && is_dir("{$targetCacheDir}-new")) {
        passthru('rm -rf ' . escapeshellarg($targetCacheDir));
        passthru('mv ' . escapeshellarg("{$targetCacheDir}-new") . ' ' . escapeshellarg($targetCacheDir));
    }
    // Docker containers are Linux by construction, so linux-<arch>-nts is the
    // canonical stub target here; zts (and any foreign-arch run) byte-compares.
    publishStubs($outputDir, $repositoryRoot, $target['ts'] === 'nts' && $arch === 'x64');
    echo "==> OK: {$outputDir}\n";
}

if ($caBundleCopied) {
    @unlink($caBundleInPath);
}

exit($failures === 0 ? 0 : 1);
