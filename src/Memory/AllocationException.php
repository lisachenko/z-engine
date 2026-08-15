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

namespace ZEngine\Memory;

/**
 * Raised when an Allocator cannot satisfy a request
 *
 * These failures happen BEFORE a single byte is handed out, so a rejected allocation
 * leaves neither the allocator nor the structure that asked for memory in a half-built
 * state. Every failure mode has a named static constructor (project convention, see
 * AGENTS.md).
 */
class AllocationException extends \RuntimeException
{
    /**
     * Raised when the requested block size is not a positive number of bytes
     */
    public static function invalidSize(int $size): self
    {
        return new self("An allocation size must be a positive number of bytes, {$size} given");
    }

    /**
     * Raised when the requested alignment is not a power of two
     */
    public static function invalidAlignment(int $alignment): self
    {
        return new self("An allocation alignment must be a power of two, {$alignment} given");
    }

    /**
     * Raised when the allocator cannot guarantee the alignment the caller asked for
     */
    public static function unsupportedAlignment(int $requested, int $guaranteed): self
    {
        return new self(
            "This allocator guarantees {$guaranteed}-byte alignment, {$requested} bytes were requested",
        );
    }
}
