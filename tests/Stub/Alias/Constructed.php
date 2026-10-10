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

namespace ZEngine\Stub\Alias;

/**
 * Class whose constructor gets redefined with a preserved alias
 */
class Constructed
{
    /** @var list<string> */
    public static array $log = [];

    public function __construct(public string $name = 'plain')
    {
        self::$log[] = 'ctor:' . $this->name;
    }
}
