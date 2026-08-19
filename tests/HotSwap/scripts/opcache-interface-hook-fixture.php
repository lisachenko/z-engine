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

// Loaded by opcache-interface-hook.php in a child process with opcache enabled.
// Deliberately declares ONLY the interface: the implementor lives in its own
// cached file, so linking it against this interface goes through the opcache
// inheritance cache (the lazy-linking path of issue #238).

interface ZEngineShmHookInterface {}
