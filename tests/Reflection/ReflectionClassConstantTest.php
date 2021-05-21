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

namespace ZEngine\Reflection;

use Error;
use PHPUnit\Framework\TestCase;
use ZEngine\Stub\TestClass;

class ReflectionClassConstantTest extends TestCase
{
    private ReflectionClassConstant $refConstant;

    protected function setUp(): void
    {
        $this->refConstant = new ReflectionClassConstant(TestClass::class, 'SOME_CONST');
    }

    public function testSetPrivate(): void
    {
        $this->refConstant->setPrivate();
        $this->assertTrue($this->refConstant->isPrivate());
        $this->assertFalse($this->refConstant->isPublic());
        $this->assertFalse($this->refConstant->isProtected());

        $this->expectException(Error::class);
        $this->expectExceptionMessageMatches('/Cannot access private const .*?SOME_CONST/');
        $this->assertSame(123, TestClass::SOME_CONST);
    }

    /**
     * @depends testSetPrivate
     */
    public function testSetProtected(): void
    {
        $this->refConstant->setProtected();
        $this->assertTrue($this->refConstant->isProtected());
        $this->assertFalse($this->refConstant->isPrivate());
        $this->assertFalse($this->refConstant->isPublic());

        // We can override+call protected method from child by making it public
        $child = new class extends TestClass {
            public function getConstant()
            {
                // return parent const which is protected now
                return parent::SOME_CONST;
            }
        };
        $value = $child->getConstant();
        $this->assertSame(123, $value);

        // If we try to access our protected constant, we should have an error here
        $this->expectException(Error::class);
        $this->expectExceptionMessageMatches('/Cannot access protected const .*?SOME_CONST/');
        $this->assertSame(123, TestClass::SOME_CONST);
    }

    /**
     * @depends testSetProtected
     */
    public function testSetPublic(): void
    {
        $this->refConstant->setPublic();
        $this->assertTrue($this->refConstant->isPublic());
        $this->assertFalse($this->refConstant->isPrivate());
        $this->assertFalse($this->refConstant->isProtected());

        $this->assertSame(123, TestClass::SOME_CONST);
    }

    public function testGetDeclaringClassReturnsCorrectInstance(): void
    {
        $class = $this->refConstant->getDeclaringClass();
        $this->assertInstanceOf(ReflectionClass::class, $class);
        $this->assertSame(TestClass::class, $class->getName());
    }

    /**
     * @group internal
     */
    public function testSetDeclaringClass(): void
    {
        try {
            $this->refConstant->setDeclaringClass(self::class);
            $this->assertSame(self::class, $this->refConstant->getDeclaringClass()->getName());
        } finally {
            $this->refConstant->setDeclaringClass(TestClass::class);
        }
    }
}
