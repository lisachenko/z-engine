<?php

/**
 * Host-side generator driver: builds the generator docker image for every
 * requested (PHP minor, thread-safety) combination and exports the generated
 * FFI artifacts into include/{minor}/{os}-{arch}-{ts}/.
 *
 * Usage:
 *   php tools/generator/generate.php               # all targets of this branch
 *   php tools/generator/generate.php --php=8.4 --ts=nts
 *
 * Requires docker (with buildx). Invoked via `composer gen-headers`.
 */

declare(strict_types=1);

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

$options = getopt('', ['php:', 'ts:']);
$targets = $defaultTargets;
if (isset($options['php']) || isset($options['ts'])) {
    $php     = is_string($options['php'] ?? null) ? $options['php'] : '8.4';
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

$failures = 0;
foreach ($targets as $target) {
    $baseImage = $mirror . ($target['ts'] === 'zts' ? "php:{$target['php']}-zts" : "php:{$target['php']}-cli");
    $outputDir = "{$repositoryRoot}/include/{$target['php']}/linux-{$arch}-{$target['ts']}";
    $command   = 'docker buildx build --progress=plain'
        . ' --build-arg ' . escapeshellarg("BASE_IMAGE={$baseImage}")
        . $proxyArguments
        . ' --output ' . escapeshellarg("type=local,dest={$outputDir}")
        . ' ' . escapeshellarg($repositoryRoot . '/tools/generator');

    echo "==> Generating {$target['php']} linux-{$arch}-{$target['ts']} from {$baseImage}\n";
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, "==> FAILED: {$target['php']} {$target['ts']} (exit {$exitCode})\n");
        $failures++;
        continue;
    }
    echo "==> OK: {$outputDir}\n";
}

if ($caBundleCopied) {
    @unlink($caBundleInPath);
}

exit($failures === 0 ? 0 : 1);
