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

namespace ZEngine\System;

/**
 * Raised when a mutation API targets an engine structure that lives in opcache
 * shared memory (ZEND_ACC_IMMUTABLE) and no writable copy-out path exists.
 *
 * Shared-memory structures are visible to every worker process of the pool, so
 * mutating them in place would corrupt sibling processes, and freeing them
 * would tear memory out from under the opcache. See docs/hot-swap.md for the
 * exact support matrix.
 */
class SharedMemoryException extends \ReflectionException {}
