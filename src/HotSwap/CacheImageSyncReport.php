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

namespace ZEngine\HotSwap;

/**
 * What one CacheImageSync::apply() run did to the live process, entry by entry
 *
 * The bridge never no-ops silently (the fail policy of the opcache epic is
 * throw-or-work): every entry of the image lands in exactly one of these
 * buckets, and everything the bridge REFUSES to apply throws out of apply()
 * instead of appearing here. Names are the canonical lowercase table keys;
 * methods are reported as "class::method".
 *
 * The "not loaded" buckets are image entries the live process has no
 * counterpart for (the script - or a method added only in the image - was
 * never loaded here): they cannot be hot-swapped, only the next include of the
 * patched binary picks them up (BinaryCacheFile::refresh()).
 */
final class CacheImageSyncReport
{
    /**
     * @param string       $scriptFile         Source path the synced image caches
     * @param list<string> $appliedFunctions   Global functions whose live body was swapped
     * @param list<string> $appliedMethods     Methods whose live body was swapped
     * @param list<string> $unchangedFunctions Live bodies already equal to the image
     * @param list<string> $unchangedMethods   Live method bodies already equal to the image
     * @param list<string> $notLoadedFunctions Image functions the live process never loaded
     * @param list<string> $notLoadedClasses   Image classes the live process never loaded
     * @param list<string> $notLoadedMethods   Image methods the live class does not declare
     *
     * @internal built by CacheImageSync::apply()
     */
    public function __construct(
        public readonly string $scriptFile,
        public readonly array $appliedFunctions,
        public readonly array $appliedMethods,
        public readonly array $unchangedFunctions,
        public readonly array $unchangedMethods,
        public readonly array $notLoadedFunctions,
        public readonly array $notLoadedClasses,
        public readonly array $notLoadedMethods,
    ) {}

    /**
     * Checks if the run swapped nothing (every live counterpart already matched)
     */
    public function isNoOp(): bool
    {
        return $this->appliedFunctions === [] && $this->appliedMethods === [];
    }
}
