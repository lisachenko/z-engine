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

/*
 * opcache.preload fixture for ClassSpecializerShmTest: every class this script declares
 * is persisted into opcache shared memory and carries ZEND_ACC_IMMUTABLE, which is the
 * only way to obtain a genuinely immutable template class entry under CLI.
 *
 * Preload constraints: no stream resources may be opened here and no functions are
 * declared - the script must only declare the template class.
 *
 * TProbePlaceholder is intentionally never defined (same convention as the TPlaceholder
 * fixtures): it is the placeholder type name the subprocess substitutes with `int`.
 */

declare(strict_types=1);

namespace ZEngine\StubShm;

class ImmutableTemplate
{
    public const TEMPLATE_CONST = 'template';

    public static int $instances = 0;

    public TProbePlaceholder $value;

    public int $count = 5;

    public function __construct(int $count = 1)
    {
        $this->count = $count;
        static::$instances++;
    }

    public function setValue(TProbePlaceholder $newValue): void
    {
        $this->value = $newValue;
    }

    public function getValue(): TProbePlaceholder
    {
        return $this->value;
    }

    public function describe(): string
    {
        return static::class . ':' . $this->count;
    }

    public static function whoAmI(): string
    {
        return static::class;
    }
}
