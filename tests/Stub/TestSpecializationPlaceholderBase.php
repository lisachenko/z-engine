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
 * Parent declaring a placeholder-typed property: substituting the placeholder while
 * specializing a CHILD must fail, because the inherited declaration is shared with
 * this class
 */
class TestSpecializationPlaceholderBase
{
    public TPlaceholder $fromBase;
}
