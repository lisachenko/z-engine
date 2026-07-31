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
use ZEngine\ClassExtension\ObjectCreateInterface;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\ClassExtension\ObjectGetClassNameInterface;

/**
 * Transparent-proxy style stub for the method/closure/constructor resolution handlers
 */
class VirtualProxy implements
    ObjectCreateInterface,
    ObjectGetClassNameInterface
{
    use ObjectCreateTrait;

    public const VIRTUAL_CLASS_NAME = 'VirtualProxyClass';

    public string $subject;

    public function __construct(string $subject = 'default')
    {
        $this->subject = $subject;
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
}
