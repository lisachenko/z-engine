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
use PHPUnit\Framework\Error\Deprecated;
use PHPUnit\Framework\TestCase;
use ZEngine\Stub\TestClass;

class ReflectionMethodTest extends TestCase
{
    private ReflectionMethod $refMethod;

    protected function setUp(): void
    {
        $this->refMethod = new ReflectionMethod(TestClass::class, 'reflectedMethod');
    }

    public function testSetFinal(): void
    {
        $this->refMethod->setFinal(true);
        $this->assertTrue($this->refMethod->isFinal());

        // If we try to override this method now in child class, then E_COMPILE_ERROR will be raised
    }

    /**
     * @depends testSetFinal
     */
    public function testSetNonFinal(): void
    {
        $this->refMethod->setFinal(false);
        $this->assertFalse($this->refMethod->isFinal());
    }

    public function testSetAbstract(): void
    {
        $this->refMethod->setAbstract(true);
        $this->assertTrue($this->refMethod->isAbstract());

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot call abstract method ZEngine\Stub\TestClass::reflectedMethod()');
        $test = new TestClass();
        $test->reflectedMethod();
    }

    /**
     * @depends testSetAbstract
     */
    public function testSetNonAbstract(): void
    {
        $this->refMethod->setAbstract(false);
        $this->assertFalse($this->refMethod->isAbstract());
        // We expect no errors here
        $test = new TestClass();
        $test->reflectedMethod();
    }

    public function testSetPrivate(): void
    {
        $this->refMethod->setPrivate();
        $this->assertTrue($this->refMethod->isPrivate());
        $this->assertFalse($this->refMethod->isPublic());
        $this->assertFalse($this->refMethod->isProtected());

        $this->expectException(Error::class);
        $this->expectExceptionMessageRegExp('/Call to private method .*?reflectedMethod()/');
        $test = new TestClass();
        $test->reflectedMethod();
    }

    /**
     * @depends testSetPrivate
     */
    public function testSetProtected(): void
    {
        $this->refMethod->setProtected();
        $this->assertTrue($this->refMethod->isProtected());
        $this->assertFalse($this->refMethod->isPrivate());
        $this->assertFalse($this->refMethod->isPublic());

        // We can override+call protected method from child by making it public
        $child = new class extends TestClass {
            public function reflectedMethod(): ?string
            {
                // call to the parent method which is protected now
                return parent::reflectedMethod();
            }
        };
        $child->reflectedMethod();

        // If we call our protected method, we should have an error here
        $this->expectException(Error::class);
        $this->expectExceptionMessageRegExp('/Call to protected method .*?reflectedMethod()/');
        $test = new TestClass();
        $test->reflectedMethod();
    }

    /**
     * @depends testSetProtected
     */
    public function testSetPublic(): void
    {
        $this->refMethod->setPublic();
        $this->assertTrue($this->refMethod->isPublic());
        $this->assertFalse($this->refMethod->isPrivate());
        $this->assertFalse($this->refMethod->isProtected());

        $test   = new TestClass();
        $result = $test->reflectedMethod();
        $this->assertSame(TestClass::class, $result);
    }

    public function testSetStatic(): void
    {
        $this->refMethod->setStatic();
        $this->assertTrue($this->refMethod->isStatic());

        $test   = new TestClass();
        $result = $test->reflectedMethod();

        // We call our method statically now, thus it should return null as class name
        $this->assertNull($result);
    }

    /**
     * @depends testSetStatic
     */
    public function testSetNonStatic(): void
    {
        $this->refMethod->setStatic(false);
        $this->assertFalse($this->refMethod->isStatic());
    }

    public function testSetDeprecated(): void
    {
        try {
            $currentReporting = error_reporting();
            error_reporting(E_ALL);
            $this->refMethod->setDeprecated();
            $this->assertTrue($this->refMethod->isDeprecated());

            $this->expectException(Deprecated::class);
            $this->expectExceptionMessageRegExp('/Function .*?reflectedMethod\(\) is deprecated/');
            $test = new TestClass();
            $test->reflectedMethod();
        } finally {
            error_reporting($currentReporting);
        }
    }

    /**
     * @depends testSetDeprecated
     */
    public function testSetNonDeprecated(): void
    {
        try {
            $currentReporting = error_reporting();
            error_reporting(E_ALL);
            $this->refMethod->setDeprecated(false);
            $this->assertFalse($this->refMethod->isDeprecated());

            // We expect no deprecation errors now
            $test = new TestClass();
            $test->reflectedMethod();
        } finally {
            error_reporting($currentReporting);
        }
    }

    public function testRedefineThrowsAnExceptionForIncompatibleCallback(): void
    {
        $this->expectException(\ReflectionException::class);
        $expectedRegexp = '/"function \(\)" should be compatible with original "function \(\)\: \?string"/';
        $this->expectExceptionMessageRegExp($expectedRegexp);

        $this->refMethod->redefine(function () {
            echo 'Nope';
        });
    }

    public function testRedefine(): void
    {
        $this->refMethod->redefine(function (): ?string {
            return 'Yes';
        });
        // Check that all main info were preserved
        $this->assertFalse($this->refMethod->isClosure());
        $this->assertSame('reflectedMethod', $this->refMethod->getName());

        $test   = new TestClass();
        $result = $test->reflectedMethod();

        // Our method now returns Yes instead of class name
        $this->assertSame('Yes', $result);
    }

    public function testGetDeclaringClassReturnsCorrectInstance(): void
    {
        $class = $this->refMethod->getDeclaringClass();
        $this->assertInstanceOf(ReflectionClass::class, $class);
        $this->assertSame(TestClass::class, $class->getName());
    }

    public function testSetDeclaringClass(): void
    {
        try {
            $this->refMethod->setDeclaringClass(self::class);
            $this->assertSame(self::class, $this->refMethod->getDeclaringClass()->getName());
        } finally {
            $this->refMethod->setDeclaringClass(TestClass::class);
        }
    }
}
