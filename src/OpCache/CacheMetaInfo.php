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
use ZEngine\Generated\zend_file_cache_metainfo;

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

    /**
     * The fields are published as public readonly and every reconstruction below passes them by
     * NAME: four of the six are plain ints, so a positional call that transposes two of them
     * compiles, runs and silently writes a corrupt cache header that only surfaces as a cache
     * miss or a wrong payload much later.
     */
    private function __construct(
        public readonly SystemId $systemId,
        public readonly int $memSize,
        public readonly int $strSize,
        public readonly int $scriptOffset,
        public readonly int $timestamp,
        public readonly int $checksum,
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
        $struct = Core::cast(zend_file_cache_metainfo::class, Core::addr($raw));
        if (FFI::string($struct->magic, strlen(self::MAGIC)) !== self::MAGIC) {
            throw OpCacheException::invalidMagic($origin);
        }

        return new self(
            systemId: SystemId::fromBinary(FFI::string($struct->system_id, SystemId::LENGTH)),
            memSize: $struct->mem_size,
            strSize: $struct->str_size,
            scriptOffset: $struct->script_offset,
            timestamp: $struct->timestamp,
            checksum: $struct->checksum,
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
        return new self(
            systemId: $systemId,
            memSize: $memSize,
            strSize: $strSize,
            scriptOffset: $scriptOffset,
            timestamp: $timestamp,
            checksum: $checksum,
        );
    }

    /**
     * The header size on disk: sizeof(zend_file_cache_metainfo), including
     * the compiler's tail padding
     *
     * @return int<1, max>
     */
    public static function byteSize(): int
    {
        $size = Core::sizeOfType(zend_file_cache_metainfo::class);
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

    /**
     * The accessors below are kept as the established reading API of this value object; each one
     * is a thin delegate to the identically named public readonly property, so both spellings
     * always agree and neither can be made to disagree by a future edit.
     */
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
        return new self(
            systemId: $this->systemId,
            memSize: $this->memSize,
            strSize: $this->strSize,
            scriptOffset: $this->scriptOffset,
            timestamp: $timestamp,
            checksum: $this->checksum,
        );
    }

    public function withChecksum(int $checksum): self
    {
        return new self(
            systemId: $this->systemId,
            memSize: $this->memSize,
            strSize: $this->strSize,
            scriptOffset: $this->scriptOffset,
            timestamp: $this->timestamp,
            checksum: $checksum,
        );
    }

    /**
     * Header with a new interned-string section size, set after re-serialization
     */
    public function withStrSize(int $strSize): self
    {
        return new self(
            systemId: $this->systemId,
            memSize: $this->memSize,
            strSize: $strSize,
            scriptOffset: $this->scriptOffset,
            timestamp: $this->timestamp,
            checksum: $this->checksum,
        );
    }

    /**
     * The exact on-disk header bytes (byteSize() long, tail padding zeroed)
     */
    public function toBinary(): string
    {
        $raw = Core::new('zend_file_cache_metainfo[1]');
        FFI::memset($raw, 0, self::byteSize());
        $struct = Core::cast(zend_file_cache_metainfo::class, Core::addr($raw));
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
