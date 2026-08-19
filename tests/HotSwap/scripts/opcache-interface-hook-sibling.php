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

// Loaded by opcache-interface-hook.php AFTER the hooked implementor: this class
// links against the UNTOUCHED cached interface, receives no handlers, and must
// therefore still be published into the opcache inheritance cache - proof that
// the zend_inheritance_cache_add interception (issue #241) declines publication
// only for the classes actually recorded in the decline set.

class ZEngineShmHookSibling implements ZEngineShmHookUntouchedInterface
{
    public int $value = 1;
}
