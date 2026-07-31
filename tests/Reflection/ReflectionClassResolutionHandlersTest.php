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
use ZEngine\ClassExtension\Hook\GetClosureHook;
use ZEngine\ClassExtension\Hook\GetConstructorHook;
use ZEngine\ClassExtension\Hook\GetPropertiesHook;
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

    #[RunInSeparateProcess]
    public function testInstallExtensionHandlersEnablesGetConstructor(): void
    {
        $refClass = new ReflectionClass(VirtualProxy::class);
        $refClass->installExtensionHandlers();

        $proxy = new VirtualProxy('injected');

        $this->assertSame(1, VirtualProxy::$constructorResolutions);
        // The stub proceeds to the engine-resolved constructor, so construction is intact
        $this->assertTrue($proxy->constructed);
        $this->assertSame('injected', $proxy->subject);
    }

    #[RunInSeparateProcess]
    public function testGetConstructorHandlerCanSkipConstruction(): void
    {
        $refClass = $this->createVirtualProxyReflection();
        $refClass->setGetConstructorHandler(function (GetConstructorHook $hook) {
            return null;
        });

        // The NEW opcode receives no constructor and skips the call entirely
        $proxy = new VirtualProxy('ignored');

        $this->assertFalse($proxy->constructed);
        $this->assertSame('uninitialized', $proxy->subject);
    }

    #[RunInSeparateProcess]
    public function testGetConstructorHandlerCanRedirectConstruction(): void
    {
        $refClass = $this->createVirtualProxyReflection();
        $refClass->setGetConstructorHandler(function (GetConstructorHook $hook) {
            $this->assertInstanceOf(VirtualProxy::class, $hook->getObject());

            return new \ReflectionMethod(VirtualProxy::class, 'altConstructor');
        });

        $proxy = new VirtualProxy('redirected');

        $this->assertTrue($proxy->constructed);
        $this->assertSame('alt-redirected', $proxy->subject);
    }

    #[RunInSeparateProcess]
    public function testGetConstructorProceedReturnsNullForConstructorlessClass(): void
    {
        $refClass       = $this->createTestClassReflection();
        $proceedResults = [];
        $refClass->setGetConstructorHandler(function (GetConstructorHook $hook) use (&$proceedResults) {
            $constructor      = $hook->proceed();
            $proceedResults[] = $constructor;

            return $constructor;
        });

        $instance = new TestClass();

        $this->assertSame(42, $instance->property);
        $this->assertSame([null], $proceedResults, 'TestClass has no constructor to resolve');
    }

    #[RunInSeparateProcess]
    public function testUninstallRestoresOriginalGetConstructorBehavior(): void
    {
        $refClass = $this->createVirtualProxyReflection();
        $hook     = $refClass->setGetConstructorHandler(function (GetConstructorHook $hook) {
            return null;
        });

        $skipped = new VirtualProxy('skipped');
        $this->assertFalse($skipped->constructed);

        $hook->uninstall();

        $constructed = new VirtualProxy('constructed');
        $this->assertTrue($constructed->constructed);
        $this->assertSame('constructed', $constructed->subject);
    }

    #[RunInSeparateProcess]
    public function testInstallExtensionHandlersEnablesGetProperties(): void
    {
        $refClass = new ReflectionClass(VirtualProxy::class);
        $refClass->installExtensionHandlers();

        // The stub does not implement Traversable - engine-level property overloading is
        // the feature under test - so the instance is annotated with its runtime behavior
        /** @var VirtualProxy&\Traversable<string, mixed> $proxy */
        $proxy = new VirtualProxy('visible');

        $expected = ['subject' => 'visible', 'virtual' => true];
        $this->assertSame($expected, self::propertySnapshot($proxy));
        $this->assertSame($expected, get_object_vars($proxy));

        $iterated = [];
        foreach ($proxy as $key => $value) {
            $iterated[$key] = $value;
        }
        $this->assertSame($expected, $iterated);
    }

    #[RunInSeparateProcess]
    public function testGetPropertiesHandlerReflectsCurrentObjectState(): void
    {
        $refClass = new ReflectionClass(VirtualProxy::class);
        $refClass->installExtensionHandlers();

        $proxy = new VirtualProxy('first');
        $this->assertSame('first', self::propertySnapshot($proxy)['subject']);

        // The table is rebuilt on every consultation, an aged snapshot is never served
        $proxy->subject = 'second';
        $this->assertSame('second', self::propertySnapshot($proxy)['subject']);

        // Stress the rebuild path: every iteration replaces the anchored table
        for ($index = 0; $index < 1000; $index++) {
            $proxy->subject = "value{$index}";
            if (self::propertySnapshot($proxy)['subject'] !== "value{$index}") {
                self::fail('Unexpected property table snapshot');
            }
        }
    }

    #[RunInSeparateProcess]
    public function testGetPropertiesProceedReturnsEngineTable(): void
    {
        $refClass = $this->createTestClassReflection();
        $refClass->setGetPropertiesHandler(function (GetPropertiesHook $hook) {
            $this->assertInstanceOf(TestClass::class, $hook->getObject());
            $properties = $hook->proceed();

            return ['decorated' => true] + $properties;
        });

        $instance = new TestClass();
        $result   = self::propertySnapshot($instance);

        $this->assertTrue($result['decorated']);
        $this->assertSame(42, $result['property']);
        // Private declared properties keep their engine name mangling
        $this->assertSame(100500, $result["\0" . TestClass::class . "\0secret"]);
    }

    #[RunInSeparateProcess]
    public function testGetPropertiesHookSurvivesGarbageCollection(): void
    {
        $refClass = new ReflectionClass(VirtualProxy::class);
        $refClass->installExtensionHandlers();

        $proxy = new VirtualProxy('gc');
        // Push the object into the GC root buffer: the container destruction delrefs the
        // object without freeing it, marking it as a possible cycle root
        $container = [$proxy, $proxy];
        unset($container);

        // The collector reaches the object through the redirected get_gc implementation,
        // which serves the anchored table without re-entering userland (see the hook docs)
        gc_collect_cycles();

        $this->assertSame('gc', self::propertySnapshot($proxy)['subject']);
    }

    #[RunInSeparateProcess]
    public function testUninstallRestoresOriginalGetPropertiesBehavior(): void
    {
        $refClass = $this->createTestClassReflection();
        $hook     = $refClass->setGetPropertiesHandler(function (GetPropertiesHook $hook) {
            return ['masked' => true];
        });

        $instance = new TestClass();
        $this->assertSame(['masked' => true], self::propertySnapshot($instance));

        $hook->uninstall();

        // The consulted instance keeps its anchored table (the standard handler serves an
        // existing zobj->properties as-is), fresh objects get the engine behavior back
        $freshInstance = new TestClass();
        $properties    = self::propertySnapshot($freshInstance);
        $this->assertSame(42, $properties['property']);
        $this->assertArrayNotHasKey('masked', $properties);
    }

    #[RunInSeparateProcess]
    public function testInstallExtensionHandlersEnablesGetClosure(): void
    {
        $refClass = new ReflectionClass(VirtualProxy::class);
        $refClass->installExtensionHandlers();

        // The stub does not implement __invoke - engine-level closure resolution is the
        // feature under test - so the instance is annotated with its runtime behavior
        /** @var VirtualProxy&callable(string=): string $proxy */
        $proxy = new VirtualProxy('closure');

        $this->assertSame('invoked-closure', $proxy());
        $this->assertSame('invoked-closure!', $proxy('!'));

        $fromCallable = \Closure::fromCallable($proxy);
        $this->assertSame('invoked-closure?', $fromCallable('?'));
    }

    #[RunInSeparateProcess]
    public function testGetClosureCheckOnlyPathIsReportedToTheHandler(): void
    {
        $refClass = new ReflectionClass(VirtualProxy::class);
        $refClass->installExtensionHandlers();

        /** @var VirtualProxy&callable(string=): string $proxy */
        $proxy = new VirtualProxy('probe');

        // is_callable() probes callability without invoking: check_only must be true
        // and the resolution must stay side-effect-free
        $this->assertTrue(is_callable($proxy));
        $this->assertSame([true], VirtualProxy::$closureChecks);
        $this->assertSame('probe', $proxy->subject);

        // A real invocation resolves with check_only = false
        $this->assertSame('invoked-probe', $proxy());
        $this->assertSame([true, false], VirtualProxy::$closureChecks);
    }

    #[RunInSeparateProcess]
    public function testGetClosureHandlerCanResolveBoundClosures(): void
    {
        $refClass = $this->createVirtualProxyReflection();
        $refClass->setGetClosureHandler(function (GetClosureHook $hook) {
            $instance = $hook->getObject();
            assert($instance instanceof VirtualProxy);

            return $instance->subjectReporter();
        });

        /** @var VirtualProxy&callable(): string $proxy */
        $proxy = new VirtualProxy('target');

        // The bound $this and scope travel through the ce/obj out-parameters
        $this->assertSame('bound-target', $proxy());
    }

    #[RunInSeparateProcess]
    public function testGetClosureProceedResolvesInvokeMethodOrNull(): void
    {
        $refClass       = $this->createFixtureReflection();
        $proceedResults = [];
        $refClass->setGetClosureHandler(function (GetClosureHook $hook) use (&$proceedResults) {
            $closure          = $hook->proceed();
            $proceedResults[] = $closure;
            assert($closure instanceof \Closure);

            return function (int $value) use ($closure): string {
                $inner = $closure($value);
                assert(is_string($inner));

                return 'wrapped-' . $inner;
            };
        });

        /** @var InvokableFixture&callable(int): string $instance */
        $instance = new InvokableFixture();

        // proceed() falls through to the engine resolution of __invoke
        $this->assertSame('wrapped-invoke-5', $instance(5));
        $this->assertCount(1, $proceedResults);
        $this->assertInstanceOf(\Closure::class, $proceedResults[0]);
    }

    #[RunInSeparateProcess]
    public function testGetClosureProceedReturnsNullForNonInvokableClass(): void
    {
        $refClass       = $this->createTestClassReflection();
        $proceedResults = [];
        $refClass->setGetClosureHandler(function (GetClosureHook $hook) use (&$proceedResults) {
            $proceedResults[] = $hook->proceed();

            return static function (): string {
                return 'fallback';
            };
        });

        /** @var TestClass&callable(): string $instance */
        $instance = new TestClass();

        $this->assertSame('fallback', $instance());
        $this->assertSame([null], $proceedResults, 'TestClass has no __invoke to resolve');
    }

    #[RunInSeparateProcess]
    public function testUninstallRestoresOriginalGetClosureBehavior(): void
    {
        $refClass = $this->createTestClassReflection();
        $hook     = $refClass->setGetClosureHandler(function (GetClosureHook $hook) {
            return static function (): string {
                return 'hooked';
            };
        });

        /** @var TestClass&callable(): string $instance */
        $instance = new TestClass();
        $this->assertSame('hooked', $instance());

        $hook->uninstall();

        // With the hook removed, the engine's original handler rejects the invocation again
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Object of type ' . TestClass::class . ' is not callable');
        $instance();
    }

    /**
     * Snapshots the engine property table of an object through an (array) cast
     *
     * The mixed-typed seam erases the declared-property array shape static analysis
     * would infer: with a get_properties hook installed the table is engine-defined
     * and has nothing to do with the declared properties.
     *
     * @return array<array-key, mixed>
     */
    private static function propertySnapshot(object $object): array
    {
        return (array) $object;
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

    /**
     * Creates a VirtualProxy reflection with the create_object handler installed,
     * so new instances receive the adjustable object handlers structure
     */
    private function createVirtualProxyReflection(): ReflectionClass
    {
        $refClass = new ReflectionClass(VirtualProxy::class);
        $refClass->setCreateObjectHandler(Closure::fromCallable([ObjectCreateTrait::class, '__init']));

        return $refClass;
    }

    /**
     * Creates an InvokableFixture reflection with the create_object handler installed,
     * so new instances receive the adjustable object handlers structure
     */
    private function createFixtureReflection(): ReflectionClass
    {
        $refClass = new ReflectionClass(InvokableFixture::class);
        $refClass->setCreateObjectHandler(Closure::fromCallable([ObjectCreateTrait::class, '__init']));

        return $refClass;
    }
}

/**
 * Fixture with a real __invoke behind the get_closure hook: used to prove that proceed()
 * falls through to the standard engine resolution (which reports the __invoke method)
 */
class InvokableFixture
{
    public function __invoke(int $value): string
    {
        return 'invoke-' . $value;
    }
}
