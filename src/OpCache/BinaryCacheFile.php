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
    private ?PayloadRelocator $relocator = null;

    private ?ReflectionOpcacheFile $view = null;

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
        if (!is_readable($binPath)) {
            throw OpCacheException::binFileNotFound($binPath);
        }
        $bytes    = self::readLocked($binPath);
        $metaInfo = CacheMetaInfo::parse($bytes, $binPath);
        $payload  = substr($bytes, CacheMetaInfo::byteSize());
        if (strlen($payload) < $metaInfo->memSize() + $metaInfo->strSize()) {
            throw OpCacheException::truncatedFile($binPath);
        }

        return new self($binPath, $metaInfo, $payload, $scriptPath);
    }

    /**
     * Reads the whole file under a shared advisory lock, so a concurrent
     * writer (another worker refreshing the same binary) cannot be observed
     * mid-write. Mirrors opcache's own zend_file_cache_flock(fd, LOCK_SH) on
     * load. Any I/O failure surfaces as a typed OpCacheException.
     */
    private static function readLocked(string $binPath): string
    {
        $handle = fopen($binPath, 'rb');
        if ($handle === false) {
            throw OpCacheException::readFailed($binPath);
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                throw OpCacheException::readFailed($binPath);
            }
            $bytes = stream_get_contents($handle);
            if ($bytes === false) {
                throw OpCacheException::readFailed($binPath);
            }

            return $bytes;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
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
        $systemId ??= SystemId::current();

        return rtrim($fileCacheDir, '/') . '/' . $systemId->toHex() . $realPath . '.bin';
    }

    /**
     * Produces a cache binary for the current build by compiling the script
     * with opcache's own serializer in a child process, then reads it back.
     *
     * This is the "generate" primitive: the result is bit-exact what opcache
     * itself would cache, ready for inspection and patching.
     *
     * @param list<string>  $extraDirectives Additional `-d name=value` ini
     *                                       directives for the child (e.g.
     *                                       `['opcache.optimization_level=0']`)
     * @param int|null       $directoryPermissions Mask used if the cache directory
     *                                       has to be created; null requires the
     *                                       directory to already exist. Never
     *                                       world-writable by default.
     */
    public static function compile(
        string $scriptPath,
        string $fileCacheDir,
        ?string $phpBinary = null,
        array $extraDirectives = [],
        ?int $directoryPermissions = 0o755,
    ): self {
        $realPath = realpath($scriptPath);
        if ($realPath === false) {
            throw OpCacheException::scriptNotFound($scriptPath);
        }
        // Opcache refuses to boot file_cache_only against a missing directory
        self::ensureDirectory($fileCacheDir, $directoryPermissions);
        $command = [
            $phpBinary ?? PHP_BINARY,
            '-d', 'opcache.enable=1',
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.file_cache=' . $fileCacheDir,
            '-d', 'opcache.file_cache_only=1',
            // The file cache refuses to store scripts while the JIT is active
            '-d', 'opcache.jit=off',
            '-d', 'opcache.jit_buffer_size=0',
        ];
        foreach ($extraDirectives as $directive) {
            $command[] = '-d';
            $command[] = $directive;
        }
        $command = [
            ...$command,
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
     * Materializes the payload into a live image and returns a Reflection-style
     * handle over its zend_persistent_script. Mutations made through the
     * returned wrappers are written back by save(). The handle is cached, so
     * repeated calls share one image.
     */
    public function getReflection(): ReflectionOpcacheFile
    {
        if ($this->view !== null) {
            return $this->view;
        }
        if (!$this->matchesCurrentBuild()) {
            throw OpCacheException::systemIdMismatch(SystemId::current(), $this->metaInfo->systemId());
        }
        $length = strlen($this->payload);
        // The relocator needs its own WRITABLE buffer: relocate() rewrites the
        // stored offsets to real addresses in place, and PHP strings are
        // immutable/copy-on-write, so $this->payload cannot be relocated
        // directly. The buffer is kept alive by the relocator it is handed to;
        // the relocated image points into it, so it must outlive the handle.
        $buffer = Core::new("char[{$length}]", false);
        Core::memcpy($buffer, $this->payload, $length);

        $this->relocator = new PayloadRelocator($buffer, $this->metaInfo);

        return $this->view = new ReflectionOpcacheFile($this->relocator->relocate());
    }

    /**
     * Writes the binary. The checksum is always recomputed from the payload;
     * pass a timestamp to match the target script's mtime (opcache compares
     * them for equality when opcache.validate_timestamps=1).
     *
     * The write is atomic: a temporary sibling file is renamed over the
     * destination, so a concurrent loader never observes a torn binary.
     */
    public function save(?string $binPath = null, ?int $timestamp = null, ?int $directoryPermissions = 0o755): void
    {
        $target = $binPath ?? $this->binPath;
        if ($this->relocator !== null) {
            // Re-serialize the (possibly mutated) live image, updating the
            // interned-string section size in the header
            $this->payload  = $this->relocator->derelocate();
            $length         = strlen($this->payload);
            $this->metaInfo = $this->metaInfo->withStrSize($length - $this->metaInfo->memSize());
        }
        $this->metaInfo = $this->metaInfo
            ->withChecksum(CacheMetaInfo::checksumOf($this->payload));
        if ($timestamp !== null) {
            $this->metaInfo = $this->metaInfo->withTimestamp($timestamp);
        }

        self::ensureDirectory(dirname($target), $directoryPermissions);
        // Write to a unique sibling under an exclusive lock, then rename over
        // the destination: the rename is atomic, so a concurrent reader (see
        // readLocked()) observes either the old or the new file, never a torn one
        $temporary = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, $this->metaInfo->toBinary() . $this->payload, LOCK_EX) === false) {
            throw OpCacheException::writeFailed($temporary);
        }
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw OpCacheException::writeFailed($target);
        }
    }

    /**
     * Ensures the target directory exists. A null permission mask means the
     * caller owns directory creation and the directory must already exist; a
     * non-null mask creates it with exactly that mask (umask still applies),
     * never implicitly world-writable.
     */
    private static function ensureDirectory(string $directory, ?int $permissions): void
    {
        if (is_dir($directory)) {
            return;
        }
        if ($permissions === null) {
            throw OpCacheException::cacheDirectoryMissing($directory);
        }
        if (!mkdir($directory, $permissions, true) && !is_dir($directory)) {
            throw OpCacheException::cacheDirectoryMissing($directory);
        }
    }

    /**
     * Writes the (patched) binary and invalidates opcache's in-memory copy of
     * the source script, so the next include picks the patched binary up.
     *
     * A script already resident in shared memory is not re-read until it is
     * invalidated, which is what this does; under opcache.file_cache_only there
     * is no shared copy and the write alone suffices. Invalidation is a no-op
     * when opcache is not active in this process (the write still happens).
     *
     * @param int|null $timestamp Source mtime to stamp; defaults to the script's
     *                            current mtime so the binary stays valid under
     *                            opcache.validate_timestamps=1
     */
    public function refresh(?int $timestamp = null): void
    {
        if ($timestamp === null && $this->scriptPath !== null && is_file($this->scriptPath)) {
            $timestamp = filemtime($this->scriptPath) ?: null;
        }
        $this->save(null, $timestamp);

        if ($this->scriptPath !== null && function_exists('opcache_invalidate')) {
            opcache_invalidate($this->scriptPath, true);
        }
    }
}
