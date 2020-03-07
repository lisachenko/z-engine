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
    public const SOME_CONST = 123;

    public int $property = 42;

    private int $secret = 100500;

    /**
     * This method will be removed during the test, do not call it or use it
     */
    private function methodToRemove(): void
    {
        die('Method should not be called and must be removed');
    }

    public function reflectedMethod(): ?string
    {
        // If we make this method static in runtime, then $this won't be passed to it
        return isset($this) ? get_class($this) : null;
    }

    public function setSecret(int $newSecret): void
    {
        $this->secret = $newSecret;
    }

    public function tellSecret(): int
    {
        return $this->secret;
    }
}
