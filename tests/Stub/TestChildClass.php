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

/**
 * Child part of the inheritance pair used by the removeParentClass()/setParent() tests
 */
class TestChildClass extends TestParentClass
{
    public const CHILD_CONST = 'child-const';

    public int $childProperty = 20;

    /**
     * Overrides the parent property: the child declaration adopts the parent's property
     * slot during linking and leaves a dead slot behind - both must survive detachment
     */
    public int $parentProperty = 30;

    /** @var list<string> */
    public static array $childStaticProperty = ['child'];

    public function childMethod(): string
    {
        return 'child';
    }
}
