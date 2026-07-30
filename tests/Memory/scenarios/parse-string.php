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

use ZEngine\Core;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

for ($index = 0; $index < 200; $index++) {
    $node = Core::$compiler->parseString('echo "iteration ' . $index . '"; $x = 1 + 2;', 'scenario.php');
    $node->dump();
    unset($node); // AstOwnership destroys the tree and frees the arena
}

gc_collect_cycles();
echo 'SCENARIO OK', PHP_EOL;
