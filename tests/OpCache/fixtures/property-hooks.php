<?php

/**
 * Fixture compiled into the opcache file cache by the property-hook relocation
 * tests.
 *
 * Exercises the prop->hooks walk of zend_file_cache_(un)serialize_prop_info: a
 * property with both get and set hooks, a get-only virtual property (NULL set
 * slot) and a set-only backed property (NULL get slot).
 */
declare(strict_types=1);

class ZEngineHookedGauge
{
    public int $level = 1 {
        get => $this->level * 10;
        set(int $value) {
            $this->level = max(0, $value);
        }
    }

    public string $label {
        get => 'gauge-' . $this->level;
    }

    public int $floor = 0 {
        set => max(0, $value);
    }
}

function zengine_bin_hooks_run(): string
{
    $gauge        = new ZEngineHookedGauge();
    $gauge->level = -5;
    $before       = $gauge->level;
    $gauge->level = 4;
    $gauge->floor = -9;

    return implode(':', [$before, $gauge->level, $gauge->label, $gauge->floor]) . ':ph-ok';
}
