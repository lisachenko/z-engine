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

namespace ZEngine\OpCache;

use ZEngine\Core;

/**
 * Engine build fingerprint (zend_system_id): 32 hex characters identifying the
 * exact PHP build. Opcache stamps it into every file-cache binary header and
 * refuses to load a binary produced by any other build.
 */
final class SystemId
{
    public const int LENGTH = 32;

    private function __construct(private readonly string $hex) {}

    /**
     * The fingerprint of the currently running engine build
     */
    public static function current(): self
    {
        return new self(Core::systemId());
    }

    /**
     * Builds an identifier from the raw 32 header bytes of a cache binary
     */
    public static function fromBinary(string $bytes): self
    {
        if (strlen($bytes) !== self::LENGTH || preg_match('/^[0-9a-f]{32}$/', $bytes) !== 1) {
            throw OpCacheException::invalidSystemId($bytes);
        }

        return new self($bytes);
    }

    public function toHex(): string
    {
        return $this->hex;
    }

    public function equals(self $other): bool
    {
        return $this->hex === $other->hex;
    }

    public function matchesCurrentBuild(): bool
    {
        return $this->hex === Core::systemId();
    }
}
