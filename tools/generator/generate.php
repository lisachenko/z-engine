<?php

/**
 * Host-side generator driver: builds the generator docker image for every
 * requested (PHP minor, thread-safety) combination and exports the generated
 * FFI artifacts into include/{minor}/{os}-{arch}-{ts}/.
 *
 * Usage:
 *   php tools/generator/generate.php               # all targets of this branch
 *   php tools/generator/generate.php --php=8.5 --ts=nts
 *   php tools/generator/generate.php --native [--php-src=DIR]
 *
 * Requires docker (with buildx). Invoked via `composer gen-headers`.
 *
 * Native mode (--native, auto-selected on non-Linux hosts) skips docker and
 * runs emit.php directly against the running PHP build - the only way to
 * generate darwin-* artifacts, since docker containers are Linux by
 * construction. It generates for the running interpreter only and fetches the
 * three php-src files emit.php slices unless --php-src points to a tree.
 */

declare(strict_types=1);

error_reporting(E_ALL);

/**
 * Version/TS targets maintained on this branch. Extend when a new platform
 * or thread-safety mode becomes supported (see AGENTS.md).
 *
 * master targets PHP 8.5 only. The committed include/8.4 artifacts are
 * maintained on the `8.4` branch (its own gen-headers run regenerates them)
 * and arrive here through the cascade merge-up.
 *
 * @var list<array{php: string, ts: string}> $defaultTargets
 */
$defaultTargets = [
    ['php' => '8.5', 'ts' => 'nts'],
    ['php' => '8.5', 'ts' => 'zts'],
];

$options = getopt('', ['php:', 'ts:', 'native', 'php-src:']);
$targets = $defaultTargets;
if (isset($options['php']) || isset($options['ts'])) {
    $php     = is_string($options['php'] ?? null) ? $options['php'] : '8.5';
    $ts      = is_string($options['ts'] ?? null) ? $options['ts'] : 'nts';
    $targets = [['php' => $php, 'ts' => $ts]];
}

$repositoryRoot = dirname(__DIR__, 2);
$machine        = php_uname('m');
$arch           = match ($machine) {
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

    $needsFetch = !isset($options['php-src']);
    $missing    = [];
    foreach (array_merge(['php-config', 'clang', 'cc'], $needsFetch ? ['curl'] : []) as $tool) {
        exec('command -v ' . escapeshellarg($tool) . ' >/dev/null 2>&1', $ignored, $exitCode);
        if ($exitCode !== 0) {
            $missing[] = $tool;
        }
    }
    if (!extension_loaded('ffi')) {
        $missing[] = 'ext-ffi (the running php must have the FFI extension)';
    }
    if ($missing !== []) {
        fwrite(STDERR, '==> ERROR: native mode needs the following on this host: ' . implode(', ', $missing) . "\n");
        exit(1);
    }
    $phpConfigVersion = trim((string) shell_exec('php-config --version 2>/dev/null'));
    if ($phpConfigVersion !== PHP_VERSION) {
        fwrite(STDERR, "==> ERROR: php-config reports {$phpConfigVersion} but the running PHP is "
            . PHP_VERSION . " - dev headers must match the running build exactly.\n");
        exit(1);
    }

    // emit.php only needs the php-src tree to slice three private-struct
    // files; fetching exactly those for the running patch release is
    // equivalent to a full checkout (see AGENTS.md).
    $phpSrc = is_string($options['php-src'] ?? null) ? $options['php-src'] : '';
    if ($phpSrc === '') {
        $phpSrc   = sys_get_temp_dir() . '/z-engine-php-src-' . PHP_VERSION;
        $caBundle = getenv('Z_ENGINE_BUILD_CA') ?: (getenv('NODE_EXTRA_CA_CERTS') ?: '');
        if (is_string($caBundle) && $caBundle !== '' && is_readable($caBundle) && getenv('CURL_CA_BUNDLE') === false) {
            putenv("CURL_CA_BUNDLE={$caBundle}");
        }
        foreach (['Zend/zend_closures.c', 'ext/opcache/ZendAccelerator.h', 'ext/opcache/zend_file_cache.c'] as $file) {
            $destination = "{$phpSrc}/{$file}";
            if (is_file($destination)) {
                continue;
            }
            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0777, true);
            }
            $url = 'https://raw.githubusercontent.com/php/php-src/php-' . PHP_VERSION . "/{$file}";
            passthru('curl -fsSL --retry 3 -o ' . escapeshellarg($destination) . ' ' . escapeshellarg($url), $exitCode);
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

    echo "==> Generating {$runningMinor} {$platform} natively from PHP " . PHP_VERSION . "\n";
    $command = escapeshellarg(PHP_BINARY) . ' -d memory_limit=2G '
        . escapeshellarg(__DIR__ . '/emit.php')
        . ' --php-src=' . escapeshellarg($phpSrc);
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, "==> FAILED: {$runningMinor} {$runningTs} (exit {$exitCode})\n");
        exit(1);
    }
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
        $cacheArguments .= ' --cache-to ' . escapeshellarg("type=local,dest={$targetCacheDir}-new,mode=max");
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
    echo "==> OK: {$outputDir}\n";
}

if ($caBundleCopied) {
    @unlink($caBundleInPath);
}

exit($failures === 0 ? 0 : 1);
