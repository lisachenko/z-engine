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

/**
 * This file should be loaded during the preload stage, which is defined by opcache.preload file.
 *
 * Requiring the autoloader is the whole script: z-engine's bootstrap recognises the preload
 * stage and publishes the engine definitions under FFI_SCOPE for the life of the server. The
 * explicit `Core::preload()` this file used to make is now redundant - it stays supported and
 * idempotent for scripts that already call it.
 */
include __DIR__.'/vendor/autoload.php';

