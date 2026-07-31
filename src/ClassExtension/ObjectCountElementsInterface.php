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

namespace ZEngine\ClassExtension;

use ZEngine\ClassExtension\Hook\CountElementsHook;

/**
 * Interface ObjectCountElementsInterface allows to intercept count($object) calls
 *
 * Classes that install a count_elements handler should also implement \Countable: debug
 * PHP builds verify every internal call against its arginfo, and count() declares
 * "Countable|array $value", so counting a non-Countable object aborts there with
 * "Arginfo / zpp mismatch" even though the handler could have served the call. The engine
 * consults the count_elements handler before ever dispatching to Countable::count(), so
 * the hook still intercepts every count($object) - Countable::count() is only reached
 * when no handler is installed. This mirrors the engine's own practice: every internal
 * class with a count_elements handler implements Countable too.
 */
interface ObjectCountElementsInterface
{
    /**
     * Performs counting of object's elements
     *
     * @return int Number of elements to report
     */
    public static function __count(CountElementsHook $hook): int;
}
