<?php

/**
 * Fixture for the CacheImageSync refusal tests: a backed enum with a method
 * body (patchable in the cache image, refused by the runtime bridge) and a
 * side function proving the not-loaded reporting path on the same image.
 */
declare(strict_types=1);

enum ZEngineImageSyncChannel: string
{
    case Stable = 'stable';
    case Beta   = 'beta';

    public function describe(): string
    {
        return 'channel-' . $this->value;
    }
}

function zengine_image_sync_enum_side(): int
{
    return 11;
}
