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

namespace ZEngine\System\Hook;

use ZEngine\Generated\zend_string;
use ZEngine\Hook\AbstractHook;
use ZEngine\Type\StringEntry;

/**
 * Receiving hook for the engine error callback (zend_error_cb)
 *
 * Fires for every engine-raised diagnostic (warnings, notices, deprecations,
 * fatal errors) BEFORE the userland error handler machinery: unlike
 * set_error_handler() it also observes diagnostics that never reach userland
 * (suppressed by error_reporting, or fatal). The severity is a raw E_* bitmask
 * value, exactly what the engine passed.
 *
 * <span style="color:red; font-weight: bold">Warning!</span> For fatal
 * severities (E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR and their
 * RECOVERABLE sibling) the handler MUST proceed(): the engine expects its
 * default callback to bail out and never return - swallowing a fatal error
 * resumes execution in a state the engine considers unreachable.
 */
final class ErrorCallbackHook extends AbstractHook
{
    protected const HOOK_FIELD = 'zend_error_cb';

    /**
     * Raw E_* severity of the diagnostic
     */
    protected int $type;

    /**
     * Raw zend_string pointer with the file name (never null: the engine always resolves one)
     *
     * @var zend_string Typed view of the engine handle; the runtime value is the raw
     *                  FFI\CData pointer (see stubs/zend-engine-structs.php)
     */
    protected object $fileName;

    /**
     * Line that raised the diagnostic
     */
    protected int $line;

    /**
     * Raw zend_string pointer with the diagnostic message
     *
     * @var zend_string Typed view of the engine handle; the runtime value is the raw
     *                  FFI\CData pointer
     */
    protected object $message;

    /**
     * void (*zend_error_cb)(int type, zend_string *error_filename, const uint32_t error_lineno, zend_string *message);
     *
     * @inheritDoc
     */
    #[\Override]
    public function handle(...$rawArguments): void
    {
        /**
         * @var int         $type     Narrowed to the stub views at the engine callback boundary
         * @var zend_string $fileName
         * @var int         $line
         * @var zend_string $message
         */
        [$type, $fileName, $line, $message] = $rawArguments;
        $this->type                         = $type;
        $this->fileName                     = $fileName;
        $this->line                         = $line;
        $this->message                      = $message;

        ($this->userHandler)($this);
    }

    /**
     * Returns the raw E_* severity value of the diagnostic
     */
    public function getErrorType(): int
    {
        return $this->type;
    }

    /**
     * Returns the name of the file that raised the diagnostic
     */
    public function getFileName(): string
    {
        return StringEntry::fromCData($this->fileName)->getStringValue();
    }

    /**
     * Returns the line that raised the diagnostic
     */
    public function getLine(): int
    {
        return $this->line;
    }

    /**
     * Returns the diagnostic message text
     */
    public function getMessage(): string
    {
        return StringEntry::fromCData($this->message)->getStringValue();
    }

    /**
     * Proceeds with the previous error callback (the engine default, or an earlier hook)
     */
    public function proceed(): void
    {
        ($this->getOriginalCallable())($this->type, $this->fileName, $this->line, $this->message);
    }
}
