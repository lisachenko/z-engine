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

namespace ZEngine\Stub\Alias;

/**
 * Unrelated class declaring a method of the same name: neither a holder nor a clone (EntryPublicationsTest)
 */
class SurveyUnrelated
{
    public function m(string $who): string
    {
        return 'unrelated:' . $who;
    }
}
