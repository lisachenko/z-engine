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

use FFI;
use ZEngine\Core;

/**
 * Immutable model of the zend_file_cache_metainfo header that opens every
 * opcache file-cache binary.
 *
 * The on-disk layout is the C struct itself, so the codec goes through the
 * generated FFI definition (guarded by layouts.json) instead of hand-written
 * offsets: a header is parsed by copying the raw bytes over a
 * zend_file_cache_metainfo instance and read back field by field.
 *
 * The checksum covers the payload and the interned-string section, NOT the
 * header itself (zend_file_cache.c writes the header before checksumming the
 * rest), so timestamp and checksum edits never invalidate each other.
 */
final class CacheMetaInfo
{
    /**
     * The magic is "OPCACHE" plus a terminating NUL (char magic[8] in C)
     */
    public const MAGIC = "OPCACHE\0";

    private function __construct(
        private readonly SystemId $systemId,
        private readonly int $memSize,
        private readonly int $strSize,
        private readonly int $scriptOffset,
        private readonly int $timestamp,
        private readonly int $checksum,
    ) {}

    /**
     * Parses the leading header bytes of a cache binary (any build)
     *
     * @param string $bytes  At least byteSize() bytes from the start of the file
     * @param string $origin Human-readable source used in diagnostics
     */
    public static function parse(string $bytes, string $origin = '(in-memory)'): self
    {
        if (strlen($bytes) < self::byteSize()) {
            throw OpCacheException::truncatedFile($origin);
        }
        $raw = Core::new('zend_file_cache_metainfo[1]');
        Core::memcpy($raw, substr($bytes, 0, self::byteSize()), self::byteSize());
        /** @var ZendFileCacheMetaInfoShape $struct */
        $struct = $raw[0];
        if (FFI::string($struct->magic, strlen(self::MAGIC)) !== self::MAGIC) {
            throw OpCacheException::invalidMagic($origin);
        }

        return new self(
            SystemId::fromBinary(FFI::string($struct->system_id, SystemId::LENGTH)),
            $struct->mem_size,
            $struct->str_size,
            $struct->script_offset,
            $struct->timestamp,
            $struct->checksum,
        );
    }

    /**
     * Assembles a header for a payload produced by the current build
     */
    public static function forPayload(
        SystemId $systemId,
        int $memSize,
        int $strSize,
        int $scriptOffset,
        int $timestamp,
        int $checksum,
    ): self {
        return new self($systemId, $memSize, $strSize, $scriptOffset, $timestamp, $checksum);
    }

    /**
     * The header size on disk: sizeof(zend_file_cache_metainfo), including
     * the compiler's tail padding
     *
     * @return int<1, max>
     */
    public static function byteSize(): int
    {
        $size = Core::sizeof(Core::type('zend_file_cache_metainfo'));
        if ($size < 1) {
            throw OpCacheException::unsupportedPayload('zend_file_cache_metainfo has no size in the loaded header');
        }

        return $size;
    }

    /**
     * adler32 (the engine's zend_adler32, seed 1) over the payload plus the
     * interned-string section - the exact bytes the loader verifies
     */
    public static function checksumOf(string $payloadAndStrings): int
    {
        return (int) hexdec(hash('adler32', $payloadAndStrings));
    }

    public function systemId(): SystemId
    {
        return $this->systemId;
    }

    public function memSize(): int
    {
        return $this->memSize;
    }

    public function strSize(): int
    {
        return $this->strSize;
    }

    public function scriptOffset(): int
    {
        return $this->scriptOffset;
    }

    public function timestamp(): int
    {
        return $this->timestamp;
    }

    public function checksum(): int
    {
        return $this->checksum;
    }

    /**
     * Header with a new source-modification timestamp: opcache compares it for
     * EQUALITY with the script's mtime when opcache.validate_timestamps=1
     */
    public function withTimestamp(int $timestamp): self
    {
        return new self($this->systemId, $this->memSize, $this->strSize, $this->scriptOffset, $timestamp, $this->checksum);
    }

    public function withChecksum(int $checksum): self
    {
        return new self($this->systemId, $this->memSize, $this->strSize, $this->scriptOffset, $this->timestamp, $checksum);
    }

    /**
     * The exact on-disk header bytes (byteSize() long, tail padding zeroed)
     */
    public function toBinary(): string
    {
        $raw = Core::new('zend_file_cache_metainfo[1]');
        FFI::memset($raw, 0, self::byteSize());
        /** @var ZendFileCacheMetaInfoShape $struct */
        $struct = $raw[0];
        Core::memcpy($struct->magic, self::MAGIC, strlen(self::MAGIC));
        Core::memcpy($struct->system_id, $this->systemId->toHex(), SystemId::LENGTH);
        $struct->mem_size      = $this->memSize;
        $struct->str_size      = $this->strSize;
        $struct->script_offset = $this->scriptOffset;
        $struct->timestamp     = $this->timestamp;
        $struct->checksum      = $this->checksum;

        return FFI::string(Core::cast('char *', Core::addr($raw)), self::byteSize());
    }
}
