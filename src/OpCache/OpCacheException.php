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

/**
 * Raised by the opcache binary file API: malformed or truncated cache
 * binaries, build mismatches, and payloads the relocator does not support.
 */
class OpCacheException extends \RuntimeException
{
    /**
     * The file does not start with the OPCACHE magic
     */
    public static function invalidMagic(string $path): self
    {
        return new self("File {$path} is not an opcache file-cache binary: missing OPCACHE magic");
    }

    /**
     * The file is shorter than its header (or the sizes recorded in it) claim
     */
    public static function truncatedFile(string $path): self
    {
        return new self("Opcache binary {$path} is truncated: file is shorter than its header describes");
    }

    /**
     * A system id must be exactly 32 lowercase hex characters
     */
    public static function invalidSystemId(string $value): self
    {
        $printable = preg_replace('/[^\x20-\x7e]/', '?', $value) ?? '';

        return new self("Invalid system id '{$printable}': expected 32 lowercase hex characters");
    }

    /**
     * The binary was produced by a different engine build and its payload
     * cannot be interpreted by this process
     */
    public static function systemIdMismatch(SystemId $expected, SystemId $actual): self
    {
        return new self(
            "Opcache binary was produced by build {$actual->toHex()}, but this process is build "
            . "{$expected->toHex()}: only the header of a foreign-build binary can be read",
        );
    }

    /**
     * The adler32 checksum recorded in the header does not match the payload
     */
    public static function checksumMismatch(int $expected, int $actual): self
    {
        return new self(sprintf(
            'Opcache binary payload checksum 0x%08x does not match the header checksum 0x%08x',
            $actual,
            $expected,
        ));
    }

    /**
     * No readable cache binary exists at the location opcache would use
     */
    public static function binFileNotFound(string $path): self
    {
        return new self("Opcache binary not found or not readable at {$path}: compile the script with file cache enabled first");
    }

    /**
     * The cache binary could not be read (open, lock or I/O failure)
     */
    public static function readFailed(string $path): self
    {
        return new self("Failed to read the opcache binary at {$path}: could not open, lock or read the file");
    }

    /**
     * The cache binary could not be written (I/O or rename failure)
     */
    public static function writeFailed(string $path): self
    {
        return new self("Failed to write the opcache binary at {$path}");
    }

    /**
     * The cache directory does not exist and no permission mask was given to
     * create it (callers that manage directory creation pass null)
     */
    public static function cacheDirectoryMissing(string $directory): self
    {
        return new self(
            "Cache directory {$directory} does not exist; create it yourself or pass a directory-permission mask",
        );
    }

    /**
     * The script a cache-binary path was requested for does not exist
     */
    public static function scriptNotFound(string $scriptPath): self
    {
        return new self("Script {$scriptPath} does not exist or cannot be resolved to a real path");
    }

    /**
     * The child compile process did not produce a cache binary
     */
    public static function compilationFailed(string $scriptPath, string $processOutput): self
    {
        return new self("Compiling {$scriptPath} into the file cache failed:\n{$processOutput}");
    }

    /**
     * The payload contains a structure shape the relocator/serializer does not
     * support; failing loudly beats silently corrupting a cache binary
     */
    public static function unsupportedPayload(string $what): self
    {
        return new self("Unsupported opcache payload structure: {$what}");
    }

    /**
     * The operation requires the payload to be relocated first
     */
    public static function payloadNotRelocated(): self
    {
        return new self('The payload is not relocated: call script() before accessing structures');
    }
}
