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

/*
 * Static-analysis-only declarations (phpstan.dist.neon scanFiles) for symbols that do
 * NOT exist at runtime by design:
 *
 *  - ZEngine\Stub\TPlaceholder is the placeholder type name the specialization
 *    templates use; the whole point of the fixtures is that it is never defined, so
 *    the engine rejects every assignment on the unspecialized template.
 *  - The ZEngine\Stub\Specialized\* classes are minted at RUNTIME by ClassSpecializer
 *    inside the tests; these stubs describe their surface for the analyser. Members
 *    whose types depend on the substitution under test are declared `mixed` on
 *    purpose - the tests assert the real (engine-enforced) types at runtime.
 *
 * This file lives outside the PSR-4 layout, so the autoloader can never load it: the
 * declarations are visible to PHPStan only.
 */

declare(strict_types=1);

namespace ZEngine\Stub;

class TPlaceholder {}

namespace ZEngine\Stub\Specialized;

use ZEngine\Stub\TestInterface;
use ZEngine\Stub\TestSpecializationBase;
use ZEngine\Stub\TestSpecializationCollection;

class BasicCopy extends TestSpecializationBase implements TestInterface {}

class DispatchCopy extends TestSpecializationBase implements TestInterface
{
    public function __construct(int $count = 1) {}

    public function describe(): string
    {
        return '';
    }

    public static function whoAmI(): string
    {
        return '';
    }
}

class TypedPropertyCopy extends TestSpecializationBase implements TestInterface
{
    public mixed $value;

    public function __construct(int $count = 1) {}
}

class MethodSignatureCopy extends TestSpecializationBase implements TestInterface
{
    public mixed $value;

    public function __construct(int $count = 1) {}

    public function setValue(mixed $newValue): void {}

    public function getValue(): mixed
    {
        return null;
    }
}

class ClassTargetCopy extends MethodSignatureCopy {}

class StaticStateCopy extends TestSpecializationBase implements TestInterface
{
    public static int $instances = 0;

    public function __construct(int $count = 1) {}
}

class ConstantsCopy extends TestSpecializationBase implements TestInterface {}

class PlainCopy extends TestSpecializationBase implements TestInterface
{
    public function __construct(int $count = 1) {}

    public function describe(): string
    {
        return '';
    }
}

class UnionCopy
{
    public mixed $union;
}

class CollectionCopy extends TestSpecializationCollection {}

class TeardownCopy extends MethodSignatureCopy {}

namespace ZEngine\StubShm;

/**
 * Placeholder type of the SHM preload fixture (tests/Stub/specializationShmPreload.php);
 * intentionally undefined at runtime
 */
class TProbePlaceholder {}

/**
 * Runtime-generated specialization minted by tests/Stub/specializationShmProbe.php;
 * mixed members on purpose - the probe asserts the engine-enforced substituted types
 */
class IntCopy
{
    public const TEMPLATE_CONST = 'template';

    public static int $instances = 0;

    public mixed $value;

    public int $count = 5;

    public function __construct(int $count = 1) {}

    public function setValue(mixed $newValue): void {}

    public function getValue(): mixed
    {
        return null;
    }

    public function describe(): string
    {
        return '';
    }

    public static function whoAmI(): string
    {
        return '';
    }
}
