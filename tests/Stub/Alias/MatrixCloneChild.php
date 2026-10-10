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
 * Autoloaded after the first redefinition of MatrixCloneBase::greet(): the interface prototype
 * gives this class an engine clone of the inherited entry instead of the entry pointer
 */
class MatrixCloneChild extends MatrixCloneBase implements MatrixCloneContract {}
