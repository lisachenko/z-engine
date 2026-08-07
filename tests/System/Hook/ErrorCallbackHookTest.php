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

use ArrayObject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZEngine\Core;

/**
 * Lifecycle of the zend_error_cb hook: observation, chaining to the engine default,
 * and clean restore
 */
#[Group('internal')]
final class ErrorCallbackHookTest extends TestCase
{
    public function testHandlerObservesDiagnosticAndProceedsToEngineDefault(): void
    {
        $log  = new ArrayObject();
        $hook = Core::setErrorCallbackHandler(function (ErrorCallbackHook $hook) use ($log): void {
            $log->append([
                'type'    => $hook->getErrorType(),
                'file'    => $hook->getFileName(),
                'line'    => $hook->getLine(),
                'message' => $hook->getMessage(),
            ]);
            $hook->proceed();
        });

        try {
            $this->assertTrue($hook->isInstalled());
            // The engine default callback is always present
            $this->assertTrue($hook->hasOriginalHandler());

            error_clear_last();
            $expectedLine = __LINE__ + 1;
            @trigger_error('observable diagnostic', E_USER_WARNING);

            $this->assertCount(1, $log);
            /** @var array{type: int, file: string, line: int, message: string} $entry */
            $entry = $log[0];
            $this->assertSame(E_USER_WARNING, $entry['type']);
            $this->assertSame(__FILE__, $entry['file']);
            $this->assertSame($expectedLine, $entry['line']);
            $this->assertSame('observable diagnostic', $entry['message']);

            // proceed() reached the engine default: error_get_last() tracked the
            // diagnostic even though @ suppressed its display
            $lastError = error_get_last();
            $this->assertNotNull($lastError);
            $this->assertSame('observable diagnostic', $lastError['message']);
        } finally {
            $hook->uninstall();
        }
        $this->assertFalse($hook->isInstalled());

        // A diagnostic raised after the restore no longer reaches the handler
        @trigger_error('unobserved diagnostic', E_USER_NOTICE);
        $this->assertCount(1, $log);
    }

    public function testHandlerObservesDiagnosticsSuppressedByErrorReporting(): void
    {
        $log  = new ArrayObject();
        $hook = Core::setErrorCallbackHandler(function (ErrorCallbackHook $hook) use ($log): void {
            $log->append($hook->getErrorType());
            $hook->proceed();
        });

        $reportingBackup = error_reporting(0);
        try {
            // Unlike set_error_handler(), zend_error_cb fires before the
            // error_reporting filter: silenced diagnostics stay observable
            trigger_error('silenced diagnostic', E_USER_DEPRECATED);
            $this->assertSame([E_USER_DEPRECATED], $log->getArrayCopy());
        } finally {
            error_reporting($reportingBackup);
            $hook->uninstall();
        }
    }

    public function testSecondHandlerChainsOnProceed(): void
    {
        $log       = new ArrayObject();
        $firstHook = Core::setErrorCallbackHandler(function (ErrorCallbackHook $hook) use ($log): void {
            $log->append('first');
            $hook->proceed();
        });
        $secondHook = Core::setErrorCallbackHandler(function (ErrorCallbackHook $hook) use ($log): void {
            $log->append('second');
            $hook->proceed();
        });

        try {
            $this->assertTrue(Core::isTopHook($secondHook));

            @trigger_error('chained diagnostic', E_USER_NOTICE);
            // Top handler fires first, its proceed() reaches the previously installed one
            $this->assertSame(['second', 'first'], $log->getArrayCopy());
        } finally {
            $secondHook->uninstall();
            $firstHook->uninstall();
        }
    }
}
