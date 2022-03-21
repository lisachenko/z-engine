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

use PHPUnit\Framework\Error\Deprecated;
use PHPUnit\Framework\TestCase;

/**
 * Test function to reflect
 */
function testFunction(): ?string
{
    return 'Test';
}

class ReflectionFunctionTest extends TestCase
{
    private ReflectionFunction $refFunction;

    protected function setUp(): void
    {
        $this->refFunction = new ReflectionFunction(__NAMESPACE__ . '\\' . 'testFunction');
    }

    public function testSetDeprecated(): void
    {
        $this->markTestSkipped('User function does not trigger deprecation error');
    }

    public function testSetInternalFunctionDeprecated(): void
    {
        try {
            $currentReporting = error_reporting();
            error_reporting(E_ALL);
            $refFunction = new ReflectionFunction('var_dump');
            $refFunction->setDeprecated();
            $this->assertTrue($refFunction->isDeprecated());
            $this->expectDeprecation();
//            $this->expectException(Deprecated::class);
//            $this->expectExceptionMessageMatches('/Function var_dump\(\) is deprecated/');
            var_dump($currentReporting);
        } finally {
            error_reporting($currentReporting);
            $refFunction->setDeprecated(false);
        }
    }

    /**
     * @group internal
     */
    public function testRedefineThrowsAnExceptionForIncompatibleCallback(): void
    {
        $this->expectException(\ReflectionException::class);
        $expectedRegexp = '/"function \(\)" should be compatible with original "function \(\)\: \?string"/';
        $this->expectExceptionMessageMatches($expectedRegexp);

        $this->refFunction->redefine(function () {
            echo 'Nope';
        });
    }

    /**
     * @group internal
     */
    public function testRedefine(): void
    {
        $this->refFunction->redefine(function (): ?string {
            return 'Yes';
        });
        // Check that all main info were preserved
        $this->assertFalse($this->refFunction->isClosure());
        $this->assertSame('testFunction', $this->refFunction->getShortName());

        $result = testFunction();

        // Our function now returns Yes instead of Test
        $this->assertSame('Yes', $result);
    }

    /**
     * @group internal
     */
    public function testRedefineInternalFunc(): void
    {
        $originalValue = zend_version();
        $refFunction   = new ReflectionFunction('zend_version');

        $refFunction->redefine(function (): string {
            return 'Z-Engine';
        });

        $modifiedValue = zend_version();
        $this->assertNotSame($originalValue, $modifiedValue);
        $this->assertSame('Z-Engine', $modifiedValue);
    }
}
