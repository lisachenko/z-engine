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
 * Parent part of the inheritance pair used by the removeParentClass()/setParent() tests
 */
class TestParentClass
{
    public const PARENT_CONST = 'parent-const';

    public int $parentProperty = 10;

    /**
     * Private parent properties occupy shadow slots in the child class that have no
     * properties_info entry - the detachment logic must drop them by slot, not by name
     */
    private string $parentSecret = 'parent secret default value';

    /** @var list<string> */
    public static array $parentStaticProperty = ['parent'];

    public function parentMethod(): string
    {
        return 'parent';
    }

    public function tellParentSecret(): string
    {
        return $this->parentSecret;
    }
}
