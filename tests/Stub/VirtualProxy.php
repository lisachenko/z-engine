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
use ZEngine\ClassExtension\Hook\GetClosureHook;
use ZEngine\ClassExtension\Hook\GetConstructorHook;
use ZEngine\ClassExtension\Hook\GetPropertiesHook;
use ZEngine\ClassExtension\ObjectCreateInterface;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\ClassExtension\ObjectGetClassNameInterface;
use ZEngine\ClassExtension\ObjectGetClosureInterface;
use ZEngine\ClassExtension\ObjectGetConstructorInterface;
use ZEngine\ClassExtension\ObjectGetPropertiesInterface;

/**
 * Transparent-proxy style stub for the method/closure/constructor resolution handlers
 */
class VirtualProxy implements
    ObjectCreateInterface,
    ObjectGetClassNameInterface,
    ObjectGetClosureInterface,
    ObjectGetConstructorInterface,
    ObjectGetPropertiesInterface
{
    use ObjectCreateTrait;

    public const VIRTUAL_CLASS_NAME = 'VirtualProxyClass';

    /**
     * Counts how many times the get_constructor handler resolved a construction
     */
    public static int $constructorResolutions = 0;

    /**
     * Records the check-only flag of every get_closure resolution
     *
     * @var list<bool>
     */
    public static array $closureChecks = [];

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
     * Produces a closure bound to this instance (used by the get_closure tests to prove
     * that the bound $this and scope travel through the out-parameters)
     */
    public function subjectReporter(): \Closure
    {
        return function (): string {
            return 'bound-' . $this->subject;
        };
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

    /**
     * @inheritDoc
     */
    public static function __getClosure(GetClosureHook $hook): \Closure
    {
        // Recording the probe flag is test bookkeeping, not an object side effect:
        // the check-only contract is about not mutating the resolved object
        self::$closureChecks[] = $hook->isCheckOnly();
        $proxy                 = $hook->getObject();
        assert($proxy instanceof self);

        return function (string $suffix = '') use ($proxy): string {
            return 'invoked-' . $proxy->subject . $suffix;
        };
    }

    /**
     * @inheritDoc
     */
    public static function __getProperties(GetPropertiesHook $hook): array
    {
        $proxy = $hook->getObject();
        assert($proxy instanceof self);

        // A fresh table is built on every call (see ObjectGetPropertiesInterface docs)
        return [
            'subject' => $proxy->subject,
            'virtual' => true,
        ];
    }
}
