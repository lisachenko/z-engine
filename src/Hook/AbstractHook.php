<?php
/**
 * Z-Engine framework
 *
 * @copyright Copyright 2020, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\Hook;

use Closure;
use FFI;
use FFI\CData;

/**
 * AbstractHook provides reusable template for installing a hook in the PHP engine
 */
abstract class AbstractHook implements HookInterface
{
    /**
     * This field should be updated in children class and accessed through LSB
     */
    protected const HOOK_FIELD = 'unknown';

    /**
     * Custom user handler
     */
    protected Closure $userHandler;

    /**
     * Holds an original handler (if present)
     */
    protected ?CData $originalHandler;

    /**
     * Contains a top-level structure that contains a field with hook
     *
     * @var CData|FFI Either raw C structure or global FFI object itself
     */
    private $rawStructure;

    public function __construct(Closure $userHandler, $rawStructure)
    {
        assert($rawStructure instanceof FFI || $rawStructure instanceof CData, 'Invalid container');
        $this->userHandler     = $userHandler;
        $this->rawStructure    = $rawStructure;
        $this->originalHandler = $rawStructure->{static::HOOK_FIELD};
    }

    /**
     * Performs installation of current hook
     *
     * <span style="color:red; font-weight: bold">WARNING!</span>
     * Please note, that this functionality is not supported on all libffi platforms, is not efficient and leaks
     * resources by the end of request.
     *
     * @link https://www.php.net/manual/en/ffi.examples-callback.php
     */
    final public function install(): void
    {
        $this->rawStructure->{static::HOOK_FIELD} = Closure::fromCallable([$this, 'handle']);
    }

    /**
     * Checks if an original handler is present to call it later with proceed
     */
    final public function hasOriginalHandler(): bool
    {
        return $this->originalHandler !== null;
    }

    /**
     * Automatic hook restore, this destructor ensures that there won't be any dead C pointers to PHP structures
     */
    final public function __destruct()
    {
        $this->rawStructure->{static::HOOK_FIELD} = $this->originalHandler;
    }

    /**
     * Internal CData fields could result in segfaults, so let's hide everything
     */
    final public function __debugInfo(): array
    {
        return [
            'userHandler' => $this->userHandler
        ];
    }
}
