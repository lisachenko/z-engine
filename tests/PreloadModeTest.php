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

namespace ZEngine;

use PHPUnit\Framework\TestCase;

/**
 * Booting under PHP's default `ffi.enable=preload` in a web SAPI
 *
 * In that mode the FFI API is restricted, outside the CLI, to code that opcache.preload
 * compiled at server start. The CLI is exempt, so the cases here run the php-cgi binary that
 * ships next to the CLI one: its SAPI is "cgi-fcgi", which is restricted exactly like php-fpm.
 * Each case needs its own process, because the thing under test is the state a process starts
 * in. The suite skips where no php-cgi sits next to PHP_BINARY; the opcache.preload cases also
 * need the opcache extension and a platform that has opcache.preload at all (not Windows).
 */
final class PreloadModeTest extends TestCase
{
    /**
     * Without a preload stage the bridge cannot bind, and the exception has to be the php.ini
     * recipe with the paths resolved for this build - not a hint to call a method the
     * operator has no place to call from
     */
    public function testInitExplainsThePreloadConfigurationWhenNothingWasPreloaded(): void
    {
        $result = $this->runCgi(['ffi.enable=preload'], <<<'PHP'
            try {
                \ZEngine\Core::init();
                echo 'booted';
            } catch (\RuntimeException $e) {
                echo $e->getMessage();
            }
            PHP);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('ffi.enable=preload', $result['stdout']);
        self::assertStringContainsString('ffi.preload=' . Core::engineHeaderPath(), $result['stdout']);
        if (PHP_OS_FAMILY === 'Windows') {
            self::assertStringContainsString('ffi.enable=1', $result['stdout']);
        } else {
            self::assertStringContainsString('opcache.preload=' . Core::preloadScriptPath(), $result['stdout']);
        }
    }

    /**
     * The recipe the exception gives has to work: with the bundled script preloaded, a
     * request process in preload mode binds the engine and uses it like the CLI would
     */
    public function testPreloadingTheBundledScriptServesARequestInPreloadMode(): void
    {
        $this->requireOpcachePreload();

        $result = $this->runCgi(
            ['ffi.enable=preload', ...$this->opcachePreloadOptions()],
            self::USE_THE_ENGINE,
        );

        self::assertSame('', $result['stderr']);
        self::assertSame('booted hello', $result['stdout']);
    }

    /**
     * ffi.preload publishes the scope at module startup; the preload stage then has to take
     * that scope instead of loading the header into it a second time - which crashed the
     * whole server start with a segmentation fault
     */
    public function testFfiPreloadAndOpcachePreloadCanBeCombined(): void
    {
        $this->requireOpcachePreload();

        $result = $this->runCgi(
            ['ffi.enable=preload', 'ffi.preload=' . Core::engineHeaderPath(), ...$this->opcachePreloadOptions()],
            self::USE_THE_ENGINE,
        );

        self::assertSame('', $result['stderr']);
        self::assertSame(0, $result['exit'], 'the process died - a crash in the preload stage shows as a signal exit');
        self::assertSame('booted hello', $result['stdout']);
    }

    /**
     * Under ffi.enable=1 the header published by ffi.preload is what the bridge binds to,
     * without any opcache.preload stage
     */
    public function testFfiPreloadServesARequestWhenFfiIsFullyEnabled(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('ffi.preload of the FFI_LIB-bound Windows header is not verified yet');
        }

        $result = $this->runCgi(
            ['ffi.enable=1', 'ffi.preload=' . Core::engineHeaderPath()],
            self::USE_THE_ENGINE,
        );

        self::assertSame('', $result['stderr']);
        self::assertSame('booted hello', $result['stdout']);
    }

    /**
     * A probe that needs the bridge bound AND makes an engine call through a wrapper that
     * the preload stage did not necessarily touch
     */
    private const string USE_THE_ENGINE = <<<'PHP'
        \ZEngine\Core::init();
        echo 'booted ', \ZEngine\Type\StringEntry::fromString('hello')->getStringValue();
        PHP;

    private function requireOpcachePreload(): void
    {
        if (!extension_loaded('Zend OPcache')) {
            self::markTestSkipped('Preloading needs the opcache extension');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('opcache.preload is not available on Windows (issue #119)');
        }
    }

    /**
     * @return list<string>
     */
    private function opcachePreloadOptions(): array
    {
        $options = ['opcache.enable=1', 'opcache.preload=' . Core::preloadScriptPath()];
        // PHP refuses to preload as root unless told which user to drop to (see AutoBootTest)
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $entry     = function_exists('posix_getpwuid') ? posix_getpwuid(0) : false;
            $options[] = 'opcache.preload_user=' . ($entry === false ? 'root' : $entry['name']);
        }

        return $options;
    }

    /**
     * Runs the probe (after requiring the autoloader) in a php-cgi process with the given
     * ini settings on top of the suite's baseline (opcache loaded, JIT off)
     *
     * @param  list<string>                                $iniSettings
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCgi(array $iniSettings, string $probe): array
    {
        $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        if (!is_executable($cgi)) {
            self::markTestSkipped("no php-cgi next to the running PHP ({$cgi})");
        }

        $script = tempnam(sys_get_temp_dir(), 'z-engine-cgi-');
        self::assertNotFalse($script);
        file_put_contents($script, sprintf(
            "<?php\ndeclare(strict_types=1);\nrequire %s;\n%s\n",
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
            $probe,
        ));

        $command = [$cgi, '-q'];
        foreach (
            [
                'opcache.jit=off',
                'opcache.jit_buffer_size=0',
                'display_errors=stderr',
                'html_errors=0',
                'error_reporting=-1',
                ...$iniSettings,
            ] as $setting
        ) {
            $command[] = '-d';
            $command[] = $setting;
        }
        $command[] = $script;

        try {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process, 'could not start a php-cgi process');

            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return ['exit' => proc_close($process), 'stdout' => trim($stdout), 'stderr' => trim($stderr)];
        } finally {
            @unlink($script);
        }
    }
}
