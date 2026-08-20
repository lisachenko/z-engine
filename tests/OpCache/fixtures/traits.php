<?php

/**
 * Fixture compiled into the opcache file cache by the trait relocation tests.
 *
 * Exercises every trait shape zend_file_cache_(un)serialize_class walks:
 * trait_names (two traits), a trait_precedences entry with an exclude list
 * (insteadof), an alias with an explicit trait name, and an alias without one
 * (NULL trait_method.class_name) that also changes visibility.
 */
declare(strict_types=1);

trait ZEngineTraitGreeter
{
    public function speak(): string
    {
        return 'greeter';
    }

    public function shared(): string
    {
        return 'greeter-shared';
    }
}

trait ZEngineTraitShouter
{
    public function shared(): string
    {
        return 'shouter-shared';
    }
}

class ZEngineTraitUser
{
    use ZEngineTraitGreeter, ZEngineTraitShouter {
        ZEngineTraitGreeter::shared insteadof ZEngineTraitShouter;
        ZEngineTraitShouter::shared as shoutedShared;
        speak as protected whisper;
    }

    public function report(): string
    {
        return implode(':', [$this->shared(), $this->shoutedShared(), $this->whisper()]);
    }
}

function zengine_bin_trait_run(): string
{
    return (new ZEngineTraitUser())->report() . ':tr-ok';
}
