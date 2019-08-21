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

namespace ZEngine;

use FFI;
use FFI\CData;

class StringEntry
{
    public CData $pointer;

    public function __construct(CData $pointer)
    {
        $this->pointer = $pointer;
    }

    public static function fromString(string $value): StringEntry
    {
        $length       = strlen($value);
        $internalSize = FFI::sizeof(Core::type('zend_string')) + $length;
        $rawMemory    = FFI::new("char[$internalSize]");
        $zendString   = Core::cast('zend_string', $rawMemory);
        $zendString->len->cdata = $length;
        FFI::memcpy(FFI::cast('char *', $zendString->val), $value, $length);

        return new static($zendString);
    }

    public function __toString()
    {
        return $this->getStringValue();
    }

    public function __debugInfo()
    {
        return ['value' => $this->getStringValue()];
    }

    /**
     * @return string
     */
    private function getStringValue(): string
    {
        return FFI::string(FFI::cast('char *', $this->pointer->val), $this->pointer->len);
    }
}
