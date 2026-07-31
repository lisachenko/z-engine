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

namespace ZEngine\Reflection;

use Closure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ZEngine\ClassExtension\Hook\GetClassNameHook;
use ZEngine\ClassExtension\ObjectCreateTrait;
use ZEngine\Stub\TestClass;
use ZEngine\Stub\VirtualProxy;

/**
 * Tests for the method/closure/constructor resolution family of object handlers
 * (get_class_name, get_constructor, get_properties, get_closure, get_method)
 */
#[Group('internal')]
class ReflectionClassResolutionHandlersTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testInstallExtensionHandlersEnablesGetClassName(): void
    {
        $refClass = new ReflectionClass(VirtualProxy::class);
        $refClass->installExtensionHandlers();

        $proxy = new VirtualProxy();

        // var_dump reports the class name through the get_class_name handler
        ob_start();
        var_dump($proxy);
        $dump = (string) ob_get_clean();
        $this->assertStringContainsString('object(' . VirtualProxy::VIRTUAL_CLASS_NAME . ')', $dump);

        // get_class()/::class read zend_class_entry.name directly and stay untouched
        $this->assertSame(VirtualProxy::class, get_class($proxy));
    }

    #[RunInSeparateProcess]
    public function testGetClassNameProceedReturnsEngineReportedName(): void
    {
        $refClass = $this->createTestClassReflection();
        $refClass->setGetClassNameHandler(function (GetClassNameHook $hook) {
            $this->assertInstanceOf(TestClass::class, $hook->getObject());

            return 'Decorated\\' . $hook->proceed();
        });

        $instance = new TestClass();

        ob_start();
        var_dump($instance);
        $dump = (string) ob_get_clean();
        $this->assertStringContainsString('object(Decorated\\' . TestClass::class . ')', $dump);
    }

    #[RunInSeparateProcess]
    public function testUninstallRestoresOriginalGetClassNameBehavior(): void
    {
        $refClass = $this->createTestClassReflection();
        $hook     = $refClass->setGetClassNameHandler(function (GetClassNameHook $hook) {
            return 'Masked';
        });

        $instance = new TestClass();

        ob_start();
        var_dump($instance);
        $dump = (string) ob_get_clean();
        $this->assertStringContainsString('object(Masked)', $dump);

        $hook->uninstall();

        ob_start();
        var_dump($instance);
        $dump = (string) ob_get_clean();
        $this->assertStringContainsString('object(' . TestClass::class . ')', $dump);
    }

    /**
     * Creates a TestClass reflection with the create_object handler installed,
     * so new instances receive the adjustable object handlers structure
     */
    private function createTestClassReflection(): ReflectionClass
    {
        $refClass = new ReflectionClass(TestClass::class);
        $refClass->setCreateObjectHandler(Closure::fromCallable([ObjectCreateTrait::class, '__init']));

        return $refClass;
    }
}
