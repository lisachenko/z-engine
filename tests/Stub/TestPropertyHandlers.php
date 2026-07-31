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

namespace ZEngine\Stub;

use FFI\CData;
use ZEngine\ClassExtension\Hook\CreateObjectHook;
use ZEngine\ClassExtension\Hook\GetPropertiesForHook;
use ZEngine\ClassExtension\Hook\HasPropertyHook;
use ZEngine\ClassExtension\Hook\UnsetPropertyHook;
use ZEngine\ClassExtension\ObjectCreateInterface;
use ZEngine\ClassExtension\ObjectGetPropertiesForInterface;
use ZEngine\ClassExtension\ObjectHasPropertyInterface;
use ZEngine\ClassExtension\ObjectUnsetPropertyInterface;

/**
 * Stub class that declares the has_property/unset_property/get_properties_for hooks
 * via the declarative ClassExtension interfaces (see installExtensionHandlers())
 */
class TestPropertyHandlers implements
    ObjectCreateInterface,
    ObjectGetPropertiesForInterface,
    ObjectHasPropertyInterface,
    ObjectUnsetPropertyInterface
{
    /**
     * Trace of hook invocations, used by tests to assert that handlers were called
     *
     * @var list<string>
     */
    public static array $log = [];

    public ?int $property = 42;

    public ?int $absent = null;

    /**
     * Same behavior as ObjectCreateTrait::__init(): proceed() declares its CData
     * return type, so this stub stays clean at PHPStan level max as-is
     *
     * @inheritDoc
     */
    public static function __init(CreateObjectHook $hook): CData
    {
        return $hook->proceed();
    }

    /**
     * @inheritDoc
     */
    public static function __fieldIsset(HasPropertyHook $hook): int
    {
        self::$log[] = 'isset:' . $hook->getMemberName();

        // Report the (null-valued) "absent" field as existing to prove that the hook controls the result
        if ($hook->getMemberName() === 'absent') {
            return 1;
        }

        return $hook->proceed();
    }

    /**
     * @inheritDoc
     */
    public static function __fieldUnset(UnsetPropertyHook $hook): void
    {
        // Swallow the unset (do not call proceed) to prove that the hook controls the result
        self::$log[] = 'unset:' . $hook->getMemberName();
    }

    /**
     * @inheritDoc
     *
     * @return array<string, bool|float|int>
     */
    public static function __getFields(GetPropertiesForHook $hook): array
    {
        self::$log[] = 'fields:' . get_class($hook->getObject());

        return ['a' => 1, 'b' => true, 'c' => 42.0];
    }
}
