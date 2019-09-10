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

namespace ZEngine\Stub;

class TestClass
{
    /**
     * This method will be removed during the test, do not call it or use it
     */
    private function methodToRemove(): void
    {
        die('Method should not be called and must be removed');
    }
}
