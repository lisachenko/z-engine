<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\System;

use PHPUnit\Framework\TestCase;
use ZEngine\Core;
use ZEngine\Type\HashTable;

class ExecutorTest extends TestCase
{
    public function testGetErrorReportingMatchesNativeFunction(): void
    {
        $this->assertSame(error_reporting(), Core::$executor->getErrorReporting());
    }

    public function testSetErrorReportingRoundTrip(): void
    {
        $original = error_reporting();
        try {
            $previous = Core::$executor->setErrorReporting(E_ERROR);
            $this->assertSame($original, $previous, 'Previous level should be returned');
            $this->assertSame(E_ERROR, Core::$executor->getErrorReporting());
            // The native function reads EG(error_reporting) directly, so it observes the change
            $this->assertSame(E_ERROR, error_reporting());

            $restored = Core::$executor->setErrorReporting($previous);
            $this->assertSame(E_ERROR, $restored);
            $this->assertSame($original, Core::$executor->getErrorReporting());
        } finally {
            error_reporting($original);
        }
        $this->assertSame($original, error_reporting());
    }

    public function testHasExceptionIsFalseDuringNormalExecution(): void
    {
        $this->assertFalse(Core::$executor->hasException());
    }

    public function testConstantTableContainsEngineConstants(): void
    {
        $constantEntry = Core::$executor->constantTable->find('PHP_VERSION');
        $this->assertNotNull($constantEntry);

        // EG(zend_constants) keys are case-sensitive since PHP 8.0
        $this->assertNull(Core::$executor->constantTable->find('php_version'));
    }

    public function testConstantTableSeesUserDefinedConstants(): void
    {
        define('ZENGINE_EXECUTOR_TEST_CONSTANT', 'executor-test');

        $constantEntry = Core::$executor->constantTable->find('ZENGINE_EXECUTOR_TEST_CONSTANT');
        $this->assertNotNull($constantEntry);
    }

    /**
     * Only the no-op path is testable from userland: the engine never executes PHP code
     * while EG(exception) is set (see testGetCurrentExceptionIsNullDuringNormalExecution),
     * and planting an exception into EG(exception) manually is VM corruption, not a test
     * setup - the next CHECK_EXCEPTION would re-dispatch a stale opline forever because
     * nothing switched the frame to EG(exception_op).
     */
    public function testSuppressCurrentExceptionIsNoOpWithoutException(): void
    {
        Core::$executor->suppressCurrentException();

        $this->assertFalse(Core::$executor->hasException());
    }

    /**
     * Only the null path is testable from userland: the engine never executes PHP code
     * while EG(exception) is set (zend_call_function refuses with a pending exception and
     * destructors run under zend_exception_save), so a live-exception assertion would
     * require C-level instrumentation.
     */
    public function testGetCurrentExceptionIsNullDuringNormalExecution(): void
    {
        $this->assertNull(Core::$executor->getCurrentException());
    }

    public function testGetUserErrorHandlerReturnsInstalledHandler(): void
    {
        $handler = static function (int $code, string $message): bool {
            return false;
        };
        set_error_handler($handler);
        try {
            $this->assertSame($handler, Core::$executor->getUserErrorHandler());
        } finally {
            restore_error_handler();
        }
    }

    public function testGetUserExceptionHandlerReturnsInstalledHandlerOrNull(): void
    {
        // Uninstall any current handler first to check the IS_UNDEF path deterministically
        $previous = set_exception_handler(null);
        try {
            $this->assertNull(Core::$executor->getUserExceptionHandler());

            $handler = static function (\Throwable $exception): void {};
            set_exception_handler($handler);
            $this->assertSame($handler, Core::$executor->getUserExceptionHandler());
        } finally {
            set_exception_handler($previous);
        }
    }

    public function testGetExitStatusIsZeroWhileRunning(): void
    {
        $this->assertSame(0, Core::$executor->getExitStatus());
    }

    public function testGetPrecisionMatchesIniSetting(): void
    {
        $this->assertSame((int) ini_get('precision'), Core::$executor->getPrecision());
    }

    public function testGetTimeoutSecondsMatchesMaxExecutionTime(): void
    {
        $this->assertSame((int) ini_get('max_execution_time'), Core::$executor->getTimeoutSeconds());
    }

    public function testIsTimedOutIsFalseWhileRunning(): void
    {
        $this->assertFalse(Core::$executor->isTimedOut());
    }

    public function testGetGlobalSymbolTableSeesGlobalVariables(): void
    {
        $GLOBALS['zEngineExecutorTestGlobal'] = 'global-scope-value';
        try {
            $symbolTable = Core::$executor->getGlobalSymbolTable();
            $this->assertInstanceOf(HashTable::class, $symbolTable);

            $entry = $symbolTable->find('zEngineExecutorTestGlobal');
            $this->assertNotNull($entry, 'Global variable should be present in EG(symbol_table)');
            $entry->getNativeValue($value);
            $this->assertSame('global-scope-value', $value);
        } finally {
            unset($GLOBALS['zEngineExecutorTestGlobal']);
        }
        $this->assertNull(Core::$executor->getGlobalSymbolTable()->find('zEngineExecutorTestGlobal'));
    }

    public function testGetIncludedFilesContainsThisTestFile(): void
    {
        $includedFiles = [];
        foreach (Core::$executor->getIncludedFiles() as $fileName => $unused) {
            $includedFiles[] = $fileName;
        }
        $this->assertContains(__FILE__, $includedFiles);
        $this->assertNotEmpty($includedFiles);
    }
}
