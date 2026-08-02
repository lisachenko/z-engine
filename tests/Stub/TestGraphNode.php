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
 * Userland graph node covering every supported slot shape of the persistent heap:
 * scalars, strings, arrays, nested/shared/cyclic object edges, mixed payloads and an
 * uninitialized typed property (IS_UNDEF slot)
 */
class TestGraphNode
{
    public ?TestGraphNode $left = null;

    public ?TestGraphNode $right = null;

    public ?TestGraphNode $parent = null;

    public string $name = '';

    public int $rank = 0;

    public float $weight = 0.0;

    public bool $active = false;

    /** @var array<int|string, mixed> */
    public array $items = [];

    public mixed $payload = null;

    /**
     * Deliberately left without a default: stays IS_UNDEF until written
     */
    public string $tag;

    public function describe(): string
    {
        return "{$this->name}#{$this->rank}";
    }
}
