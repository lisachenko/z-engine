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

use ZEngine\Core;

ini_set('display_errors', 'on');

// Requiring the autoloader is the whole boot - bootstrap.php runs from autoload.files.
include __DIR__ . '/../vendor/autoload.php';

// That boot is deliberately silent on a host that cannot run the engine, which is right for a
// library but wrong for this suite: every test here drives the engine, so an unsupported host
// has to say so now rather than through 900 confusing failures. init() is idempotent, so after
// a successful auto-boot this is a no-op. AutoBootTest proves the automatic path in child
// processes, where it can be observed without this line interfering.
Core::init();
