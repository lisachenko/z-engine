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

namespace ZEngine\System\Hook;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end firing path of the observer bridge, verified against a real startup-time observer
 * provider: the test compiles the minimal observer_enabler extension
 * (tests/fixtures/observer-enabler) on demand with the local PHP toolchain, loads it into a child
 * process together with the opcache.preload fixture, and asserts on the event log the fixture
 * produces - begin/end firing with return values, clean uninstall, nested-call ordering,
 * begin-only observation of throwing functions, callback-exception containment, and
 * internal-function observation.
 *
 * A second child pins the documented hard limitation: an END handler attached to a function that
 * throws is invoked by the engine during unwinding, and ext/ffi aborts the process before any PHP
 * runs ("Throwing from FFI callbacks is not allowed") - if a future PHP release lifts this, the
 * pin fails and the begin-only restriction can be revisited.
 *
 * Skips cleanly when the toolchain (phpize / cc) or opcache is unavailable, so environments
 * without build tools stay green.
 */
final class ObserverHookFiringTest extends TestCase
{
    private static ?string $extensionPath = null;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('Zend OPcache')) {
            self::markTestSkipped('opcache is required to exercise the preload path');
        }
        self::$extensionPath = self::buildEnablerExtension();
    }

    public function testObserverHookFiresForUserlandAndInternalFunctions(): void
    {
        $report = $this->runFiringChild('firing', $exitCode, $stdout, $stderr, $context);

        self::assertSame(0, $exitCode, "Firing child exited abnormally\n{$context}");
        self::assertStringContainsString('REQUEST_OK', $stdout, $context);

        // The provider enabled the machinery before the preload script ran
        self::assertStringContainsString('PRELOADED=1', $report, $context);
        self::assertStringContainsString('USER_ENABLED=1', $report, $context);
        self::assertStringContainsString('INTERNAL_ENABLED=1', $report, $context);
        self::assertStringContainsString('OBSERVER_COUNT=1', $report, $context);

        // 1. begin fires on entry, end fires on return and sees the return value
        self::assertStringContainsString('SIMPLE_RESULT=42', $report, $context);
        self::assertStringContainsString(
            'SIMPLE_EVENTS=begin:zengine_observed_simple,end:zengine_observed_simple=42',
            $report,
            $context,
        );

        // 2. uninstall detaches cleanly: same function, no further events
        self::assertStringContainsString('AFTER_UNINSTALL_RESULT=10', $report, $context);
        self::assertStringContainsString("AFTER_UNINSTALL_EVENTS=\n", $report, $context);

        // 3. nested calls: outer-begin, inner-begin, inner-end, outer-end
        self::assertStringContainsString('NESTED_RESULT=12', $report, $context);
        self::assertStringContainsString(
            'NESTED_EVENTS=begin:zengine_observed_outer,begin:zengine_observed_inner,'
            . 'end:zengine_observed_inner=2,end:zengine_observed_outer=12',
            $report,
            $context,
        );

        // 4. a throwing observed function propagates normally under a begin-only hook
        self::assertStringContainsString('THROW_CAUGHT=observed failure', $report, $context);
        self::assertStringContainsString('THROW_EVENTS=begin:zengine_observed_thrower', $report, $context);

        // 5. an exception in the begin callback is contained (E_USER_WARNING), execution and
        //    the end handler continue unharmed
        self::assertStringContainsString('CONTAINED_RESULT=6', $report, $context);
        self::assertStringContainsString(
            'CONTAINED_WARNING=Observer begin callback threw LogicException: callback exploded',
            $report,
            $context,
        );
        self::assertStringContainsString('CONTAINED_EVENTS=end-after-broken-begin', $report, $context);

        // 6. internal functions are observed through the internal-function extension slot
        self::assertStringContainsString('INTERNAL_RESULT=cba', $report, $context);
        self::assertStringContainsString("INTERNAL_EVENTS=begin:strrev,end:strrev='cba'", $report, $context);

        self::assertStringContainsString('DONE', $report, $context);
    }

    public function testEndHandlerOnThrowingFunctionAbortsPinnedLimitation(): void
    {
        $report = $this->runFiringChild('throw-with-end', $exitCode, $stdout, $stderr, $context);

        // The hook attached and the call started...
        self::assertStringContainsString('THROW_WITH_END=armed', $report, $context);
        // ...but ext/ffi aborted the process while the engine unwound the throwing frame:
        // the catch block never ran and the process died with the documented fatal error.
        self::assertStringNotContainsString('THROW_WITH_END=caught', $report, $context);
        self::assertStringNotContainsString('THROW_WITH_END=survived', $report, $context);
        self::assertNotSame(0, $exitCode, "Expected the child to abort\n{$context}");
        self::assertStringContainsString('Throwing from FFI callbacks is not allowed', $stdout . $stderr, $context);
    }

    /**
     * Launches the preload firing fixture in a child process and returns its report
     *
     * @param-out int    $exitCode
     * @param-out string $stdout
     * @param-out string $stderr
     * @param-out string $context
     */
    private function runFiringChild(
        string $scenario,
        ?int &$exitCode,
        ?string &$stdout,
        ?string &$stderr,
        ?string &$context,
    ): string {
        self::assertIsString(self::$extensionPath);
        $fixture   = dirname(__DIR__, 2) . '/Stub/observerFiringProbe.php';
        $reportOut = tempnam(sys_get_temp_dir(), 'zobs_');
        self::assertIsString($reportOut);

        $command = [
            PHP_BINARY,
            '-d', 'extension=' . self::$extensionPath,
            '-d', 'ffi.enable=1',
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.jit=off',
            '-d', 'opcache.preload=' . $fixture,
            '-r', 'echo "REQUEST_OK\n";',
        ];

        /** @var array<string, string> $inheritedEnv */
        $inheritedEnv = getenv();
        $environment  = ['ZOBS_OUT' => $reportOut, 'ZOBS_SCENARIO' => $scenario] + $inheritedEnv;
        $process      = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment,
        );
        self::assertIsResource($process, 'Unable to spawn the firing child process');

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report = (string) file_get_contents($reportOut);
        @unlink($reportOut);
        $context = "SCENARIO={$scenario} EXIT={$exitCode}\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}\nREPORT:\n{$report}";

        if (str_contains($stderr, 'Preloading is not supported') || str_contains($stderr, 'preload_user')) {
            self::markTestSkipped("Preloading unavailable in this environment:\n{$context}");
        }

        return $report;
    }

    /**
     * Builds (or reuses) the observer_enabler provider extension with the local PHP toolchain
     *
     * The build runs in a cached temp directory keyed by source hash and PHP version, so repeated
     * test runs reuse the compiled .so. Skips the whole test class when the toolchain is missing.
     */
    private static function buildEnablerExtension(): string
    {
        foreach (['phpize', 'cc', 'make'] as $tool) {
            if (self::runCommand(['sh', '-c', "command -v {$tool}"], sys_get_temp_dir()) !== 0) {
                self::markTestSkipped("Build tool '{$tool}' is not available");
            }
        }

        $sourceDir = dirname(__DIR__, 2) . '/fixtures/observer-enabler';
        $source    = $sourceDir . '/observer_enabler.c';
        $configM4  = $sourceDir . '/config.m4';
        $cacheKey  = substr(md5(PHP_VERSION_ID . '|' . md5_file($source) . '|' . md5_file($configM4)), 0, 12);
        $buildDir  = sys_get_temp_dir() . '/z-engine-observer-enabler-' . $cacheKey;
        $module    = $buildDir . '/modules/observer_enabler.so';

        if (is_file($module)) {
            return $module;
        }

        if (!is_dir($buildDir) && !mkdir($buildDir, 0777, true) && !is_dir($buildDir)) {
            self::markTestSkipped("Cannot create build directory {$buildDir}");
        }
        copy($source, $buildDir . '/observer_enabler.c');
        copy($configM4, $buildDir . '/config.m4');

        foreach ([['phpize'], ['sh', '-c', './configure --quiet'], ['make', '-s']] as $step) {
            if (self::runCommand($step, $buildDir, $output) !== 0) {
                self::markTestSkipped('Building observer_enabler failed at "' . implode(' ', $step) . "\":\n{$output}");
            }
        }
        if (!is_file($module)) {
            self::markTestSkipped('observer_enabler build completed but produced no module');
        }

        return $module;
    }

    /**
     * Runs a build command in a working directory, capturing combined output
     *
     * @param list<string> $command
     * @param-out string   $output
     */
    private static function runCommand(array $command, string $workingDirectory, ?string &$output = null): int
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
        );
        if (!is_resource($process)) {
            $output = 'proc_open failed';

            return 1;
        }
        $output = (stream_get_contents($pipes[1]) ?: '') . (stream_get_contents($pipes[2]) ?: '');
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }
}
