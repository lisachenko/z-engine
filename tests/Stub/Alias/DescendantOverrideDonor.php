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
 * Donor for an addMethod() override of the inherited Ancestor::inheritedOnly() on Descendant
 *
 * Extends Ancestor so that parent:: compiles; at run time parent:: resolves against
 * the class the body is published in.
 */
class DescendantOverrideDonor extends Ancestor
{
    public function inheritedOnly(int $x): int
    {
        return 1000 + parent::inheritedOnly($x);
    }
}
