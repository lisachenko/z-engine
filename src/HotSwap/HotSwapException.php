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

namespace ZEngine\HotSwap;

/**
 * Raised when a class delta cannot be prepared (unparsable or incompatible source,
 * unsupported shape change - see the support matrix in docs/hot-swap.md) or when
 * an apply step fails and the delta was rolled back.
 */
class HotSwapException extends \ReflectionException {}
