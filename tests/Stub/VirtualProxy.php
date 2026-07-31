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

namespace ZEngine\Stub;

use ZEngine\ClassExtension\Hook\GetClassNameHook;
use ZEngine\ClassExtension\Hook\GetConstructorHook;
use ZEngine\ClassExtension\ObjectCreateInterface;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\ClassExtension\ObjectGetClassNameInterface;
use ZEngine\ClassExtension\ObjectGetConstructorInterface;

/**
 * Transparent-proxy style stub for the method/closure/constructor resolution handlers
 */
class VirtualProxy implements
    ObjectCreateInterface,
    ObjectGetClassNameInterface,
    ObjectGetConstructorInterface
{
    use ObjectCreateTrait;

    public const VIRTUAL_CLASS_NAME = 'VirtualProxyClass';

    /**
     * Counts how many times the get_constructor handler resolved a construction
     */
    public static int $constructorResolutions = 0;

    public string $subject = 'uninitialized';

    public bool $constructed = false;

    public function __construct(string $subject = 'default')
    {
        $this->subject     = $subject;
        $this->constructed = true;
    }

    /**
     * Alternative constructor target used by the get_constructor redirection tests
     */
    public function altConstructor(string $subject = 'default'): void
    {
        $this->subject     = 'alt-' . $subject;
        $this->constructed = true;
    }

    /**
     * Regular defined method used as a redirection target by the get_method handler
     */
    public function realMethod(): string
    {
        return 'real-' . $this->subject;
    }

    /**
     * @inheritDoc
     */
    public static function __getClassName(GetClassNameHook $hook): string
    {
        return self::VIRTUAL_CLASS_NAME;
    }

    /**
     * @inheritDoc
     */
    public static function __getConstructor(GetConstructorHook $hook): ?\ReflectionMethod
    {
        self::$constructorResolutions++;

        return $hook->proceed();
    }
}
