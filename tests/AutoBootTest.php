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

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Covers bootstrap.php, the autoload.files entry that boots the bridge (issue #21)
 *
 * Every case here needs its own process: the thing under test happens once, at autoload time,
 * before any test code exists. In-process assertions can only see the result of the boot this
 * process already did.
 *
 * The preload case is the one that matters. Automatic initialization was proposed in 2019 and
 * abandoned because "the composer autoloader calls Core::init() before preload initialization" -
 * an unconditional boot binds the definitions with FFI::cdef(), which lasts for the preload
 * request only, and leaves an engine behind that makes the script's own Core::preload() a no-op.
 * The server then starts and every request afterwards fails. That failure is invisible to any
 * in-process test, so it gets a child process with opcache.preload actually set.
 */
#[Group('opcache')]
final class AutoBootTest extends TestCase
{
    /**
     * Only the two preload cases need it; the opt-out and silent-failure cases are plain
     * autoload behaviour and run everywhere
     */
    private function requirePreloadSupport(): void
    {
        if (!extension_loaded('Zend OPcache')) {
            self::markTestSkipped('Preloading needs the opcache extension');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('opcache.preload is not available on Windows (issue #119)');
        }
    }

    /**
     * The regression issue #21 was closed on: preloading has to reach the next request
     *
     * The probe never calls init() or preload() itself and never registers an autoloader, so a
     * ready bridge can only come from the preload stage having published the definitions under
     * FFI_SCOPE - which is exactly what FFI::cdef() would not do.
     */
    public function testPreloadingThroughTheAutoloaderServesTheFollowingRequest(): void
    {
        $this->requirePreloadSupport();

        $result = $this->runWithPreload(
            dirname(__DIR__) . '/preload.php',
            'echo \ZEngine\Core::isInitialized() ? "booted" : "not booted";',
        );

        self::assertSame('', $result['stderr'], 'the preload stage reported an error');
        self::assertSame(0, $result['exit']);
        self::assertSame('booted', $result['stdout']);
    }

    /**
     * The explicit call an existing preload script still makes must stay harmless
     */
    public function testAnExplicitPreloadCallOnTopOfTheAutomaticOneIsHarmless(): void
    {
        $this->requirePreloadSupport();

        $script = $this->writeScratchPreloadScript('\ZEngine\Core::preload();');

        try {
            $result = $this->runWithPreload(
                $script,
                'echo \ZEngine\Core::isInitialized() ? "booted" : "not booted";',
            );

            self::assertSame('', $result['stderr']);
            self::assertSame('booted', $result['stdout']);
        } finally {
            @unlink($script);
        }
    }

    public function testAutoBootCanBeDisabled(): void
    {
        $result = $this->runProbe(
            'echo \ZEngine\Core::isInitialized() ? "booted" : "not booted";',
            ['ZENGINE_AUTOBOOT' => '0'],
        );

        self::assertSame('not booted', $result['stdout']);
    }

    /**
     * A host that cannot run the engine still has to be able to autoload the package
     */
    public function testAutoloadingIsSilentWhenTheEngineCannotBoot(): void
    {
        $result = $this->runProbe(
            'echo \ZEngine\Core::isInitialized() ? "booted" : "not booted";',
            [],
            ['-d', 'ffi.enable=0'],
        );

        self::assertSame('', $result['stderr'], 'autoloading must not fail on a host without usable FFI');
        self::assertSame(0, $result['exit']);
        self::assertSame('not booted', $result['stdout']);
    }

    private function writeScratchPreloadScript(string $body): string
    {
        $path = sys_get_temp_dir() . '/z-engine-preload-' . getmypid() . '.php';
        file_put_contents($path, sprintf(
            "<?php\ndeclare(strict_types=1);\nrequire_once %s;\n%s\n",
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
            $body,
        ));

        return $path;
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runWithPreload(string $preloadScript, string $probe): array
    {
        $options = ['-d', 'opcache.preload=' . $preloadScript];

        // PHP refuses to preload as root unless told which user to drop to, and CI runners are
        // not root while a container often is - so the option is added only when it is required
        $user = $this->currentUserName();
        if ($user !== null) {
            $options[] = '-d';
            $options[] = 'opcache.preload_user=' . $user;
        }

        return $this->runProbe($probe, [], $options);
    }

    /**
     * @param  array<string, string>                       $environment
     * @param  list<string>                                $options
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runProbe(string $probe, array $environment = [], array $options = []): array
    {
        $command = [
            PHP_BINARY,
            '-d', 'ffi.enable=1',
            '-d', 'opcache.enable=1',
            '-d', 'opcache.enable_cli=1',
            // The JIT rewrites the executor internals z-engine hooks into
            '-d', 'opcache.jit=off',
            '-d', 'opcache.jit_buffer_size=0',
            '-d', 'display_errors=stderr',
            '-d', 'error_reporting=-1',
            ...$options,
            '-r',
            sprintf('require %s; %s', var_export(dirname(__DIR__) . '/vendor/autoload.php', true), $probe),
        ];

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment === [] ? null : [...getenv(), ...$environment],
        );
        self::assertIsResource($process, 'could not start a child PHP process');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => trim($stdout), 'stderr' => trim($stderr)];
    }

    private function currentUserName(): ?string
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return null;
        }
        $entry = function_exists('posix_getpwuid') ? posix_getpwuid(0) : false;

        return $entry === false ? 'root' : $entry['name'];
    }
}
