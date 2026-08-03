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

namespace ZEngine\Reflection;

/**
 * Thrown when a class cannot be specialized (unsupported source class kind, name
 * collision, or a type substitution that cannot be applied safely).
 *
 * Every unsupported case fails with this exception BEFORE any engine state is
 * modified: a failed specialize() call never leaves a half-built class behind.
 * The support matrix is documented in docs/class-specialization.md.
 */
final class ClassSpecializationException extends \ReflectionException {}
