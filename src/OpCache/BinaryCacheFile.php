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
 * An opcache file-cache binary (`opcache.file_cache` .bin file).
 *
 * The header of any binary can be read regardless of which build produced it;
 * the payload can only be interpreted (script()) when the binary's system id
 * matches the running build. Writing always recomputes the adler32 checksum,
 * so a modified binary stays loadable with
 * opcache.file_cache_consistency_checks=1.
 *
 * Path layout on this platform mirrors zend_file_cache_get_bin_file_path():
 * file_cache_dir + '/' + system_id + realpath(script) + '.bin'.
 */
final class BinaryCacheFile
{
    private function __construct(
        private readonly string $binPath,
        private CacheMetaInfo $metaInfo,
        private string $payload,
        private readonly ?string $scriptPath = null,
    ) {}

    /**
     * Reads a cache binary from disk. Works for binaries from any engine
     * build - only the payload interpretation requires a build match.
     *
     * @param string|null $scriptPath The source script this binary caches (used by refresh())
     */
    public static function read(string $binPath, ?string $scriptPath = null): self
    {
        if (!is_file($binPath)) {
            throw OpCacheException::binFileNotFound($binPath);
        }
        $bytes    = (string) file_get_contents($binPath);
        $metaInfo = CacheMetaInfo::parse($bytes, $binPath);
        $payload  = substr($bytes, CacheMetaInfo::byteSize());
        if (strlen($payload) < $metaInfo->memSize() + $metaInfo->strSize()) {
            throw OpCacheException::truncatedFile($binPath);
        }

        return new self($binPath, $metaInfo, $payload, $scriptPath);
    }

    /**
     * Computes the path opcache uses for a script's cache binary
     *
     * @param SystemId|null $systemId Defaults to the current build
     */
    public static function locate(string $fileCacheDir, string $scriptPath, ?SystemId $systemId = null): string
    {
        $realPath = realpath($scriptPath);
        if ($realPath === false) {
            throw OpCacheException::scriptNotFound($scriptPath);
        }
        $systemId = $systemId ?? SystemId::current();

        return rtrim($fileCacheDir, '/') . '/' . $systemId->toHex() . $realPath . '.bin';
    }

    /**
     * Produces a cache binary for the current build by compiling the script
     * with opcache's own serializer in a child process, then reads it back.
     *
     * This is the "generate" primitive: the result is bit-exact what opcache
     * itself would cache, ready for inspection and patching.
     */
    public static function compile(string $scriptPath, string $fileCacheDir, ?string $phpBinary = null): self
    {
        $realPath = realpath($scriptPath);
        if ($realPath === false) {
            throw OpCacheException::scriptNotFound($scriptPath);
        }
        // Opcache refuses to boot file_cache_only against a missing directory
        if (!is_dir($fileCacheDir) && !mkdir($fileCacheDir, 0777, true) && !is_dir($fileCacheDir)) {
            throw OpCacheException::compilationFailed($realPath, "cannot create the cache directory {$fileCacheDir}");
        }
        $command = [
            $phpBinary ?? PHP_BINARY,
            '-d', 'opcache.enable=1',
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.file_cache=' . $fileCacheDir,
            '-d', 'opcache.file_cache_only=1',
            // The file cache refuses to store scripts while the JIT is active
            '-d', 'opcache.jit=off',
            '-d', 'opcache.jit_buffer_size=0',
            '-r', 'exit(function_exists("opcache_compile_file") ? (opcache_compile_file($argv[1]) ? 0 : 1) : 2);',
            '--',
            $realPath,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw OpCacheException::compilationFailed($realPath, 'unable to spawn the compile process');
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw OpCacheException::compilationFailed(
                $realPath,
                "child exited with code {$exitCode}\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
            );
        }

        return self::read(self::locate($fileCacheDir, $realPath), $realPath);
    }

    public function binPath(): string
    {
        return $this->binPath;
    }

    public function scriptPath(): ?string
    {
        return $this->scriptPath;
    }

    public function metaInfo(): CacheMetaInfo
    {
        return $this->metaInfo;
    }

    public function matchesCurrentBuild(): bool
    {
        return $this->metaInfo->systemId()->matchesCurrentBuild();
    }

    /**
     * Verifies the payload against the header checksum - the same check the
     * loader performs with opcache.file_cache_consistency_checks=1
     */
    public function verifyChecksum(): bool
    {
        return CacheMetaInfo::checksumOf($this->payload) === $this->metaInfo->checksum();
    }

    /**
     * The raw serialized payload: mem_size buffer bytes followed by the
     * str_size interned-string section
     */
    public function payload(): string
    {
        return $this->payload;
    }

    /**
     * Writes the binary. The checksum is always recomputed from the payload;
     * pass a timestamp to match the target script's mtime (opcache compares
     * them for equality when opcache.validate_timestamps=1).
     *
     * The write is atomic: a temporary sibling file is renamed over the
     * destination, so a concurrent loader never observes a torn binary.
     */
    public function save(?string $binPath = null, ?int $timestamp = null): void
    {
        $target         = $binPath ?? $this->binPath;
        $this->metaInfo = $this->metaInfo
            ->withChecksum(CacheMetaInfo::checksumOf($this->payload));
        if ($timestamp !== null) {
            $this->metaInfo = $this->metaInfo->withTimestamp($timestamp);
        }

        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw OpCacheException::compilationFailed($target, "cannot create the cache directory {$directory}");
        }
        $temporary = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
        file_put_contents($temporary, $this->metaInfo->toBinary() . $this->payload);
        rename($temporary, $target);
    }
}
