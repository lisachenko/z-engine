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

namespace ZEngine\Type;


use PHPUnit\Framework\TestCase;
use ZEngine\Reflection\ReflectionValue;

class ReferenceEntryTest extends TestCase
{
    public function testGetValue()
    {
        $value     = 'some';
        $reference = new ReferenceEntry($value);

        // At that point we will get a ReflectionValue instance of original variable
        $refValue = $reference->getValue();
        $this->assertInstanceOf(ReflectionValue::class, $refValue);
        $__fakeReturn = null;
        $this->assertSame($value, $refValue->getNativeValue($__fakeReturn));
    }
}
