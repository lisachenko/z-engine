<?php
/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */
declare(strict_types=1);

include __DIR__.'/vendor/autoload.php';

use ZEngine\Core;

/**
 * This file should be loaded during the preload stage, which is defined by opcache.preload file.
 * Either include it manually, or just add following line into your init section.
 */
Core::preload();

