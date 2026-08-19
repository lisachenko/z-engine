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

// Loaded by opcache-interface-hook.php AFTER the interface hook was installed:
// declaring this class links it against the cached interface, which fires the
// interface_gets_implemented hook on the lazy-linking temporary (issue #238).

class ZEngineShmHookImplementor implements ZEngineShmHookInterface
{
    public int $value = 1;
}
