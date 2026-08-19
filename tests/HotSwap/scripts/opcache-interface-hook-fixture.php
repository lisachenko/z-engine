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
// Deliberately declares ONLY interfaces: the implementors live in their own
// cached files, so linking them against these interfaces goes through the
// opcache inheritance cache (the lazy-linking path of issue #238).

// The hooked interface: an interface_gets_implemented handler is installed on it,
// so its implementor receives handlers mid-linking and must be kept process-local
// by declining its inheritance-cache publication (issue #241)
interface ZEngineShmHookInterface {}

// The untouched control: no hook, no handlers - its implementor must still be
// published into the inheritance cache (the interception delegates to opcache)
interface ZEngineShmHookUntouchedInterface {}
