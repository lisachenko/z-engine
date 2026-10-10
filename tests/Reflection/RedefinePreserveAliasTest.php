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

use PHPUnit\Framework\TestCase;
use ZEngine\Core;
use ZEngine\OpCache\SharedMemoryException;
use ZEngine\Stub\Alias\Ancestor;
use ZEngine\Stub\Alias\AncestorDonor;
use ZEngine\Stub\Alias\Constructed;
use ZEngine\Stub\Alias\ConstructedDonor;
use ZEngine\Stub\Alias\Counter;
use ZEngine\Stub\Alias\CounterDonor;
use ZEngine\Stub\Alias\Descendant;
use ZEngine\Stub\Alias\DescendantDonor;
use ZEngine\Stub\Alias\DescendantOverrideDonor;
use ZEngine\Stub\Alias\EarlyBase;
use ZEngine\Stub\Alias\EarlyDonor;
use ZEngine\Stub\Alias\EarlyOverrideBase;
use ZEngine\Stub\Alias\EarlyOverrideDonor;
use ZEngine\Stub\Alias\EarlyOverrideLeaf;
use ZEngine\Stub\Alias\EarlyOverrideMid;
use ZEngine\Stub\Alias\EarlySub;
use ZEngine\Stub\Alias\GrandDescendant;
use ZEngine\Stub\Alias\Greeter;
use ZEngine\Stub\Alias\GreeterDonor;
use ZEngine\Stub\Alias\Invalid;
use ZEngine\Stub\Alias\InvalidAbstract;
use ZEngine\Stub\Alias\OverrideBase;
use ZEngine\Stub\Alias\OverrideDerived;
use ZEngine\Stub\Alias\OverrideDerivedSub;
use ZEngine\Stub\Alias\OverrideDonor;
use ZEngine\Stub\Alias\OverrideSealedDonor;
use ZEngine\Stub\Alias\Secretive;
use ZEngine\Stub\Alias\SecretiveDonor;
use ZEngine\Stub\Alias\Statics;
use ZEngine\Stub\Alias\StaticsChild;
use ZEngine\Stub\Alias\StaticsDonor;
use ZEngine\Stub\Alias\Twice;

/**
 * redefine() with a method donor and a preserved alias of the previous body, and
 * addMethod() publishing a donor body as the override of an inherited method
 *
 * Every scenario owns its stub classes (tests/Stub/Alias): a redefinition changes the
 * class for the rest of the process, so no two tests may share a target. Subclasses of a
 * redefined class are autoloaded only AFTER the redefinition, which is the order the
 * engine supports for opcache-shared classes (see the "linked before" tests for the
 * refused order). With opcache active in the runner the stubs are shared-memory classes
 * and every redefinition exercises the copy-out path.
 */
class RedefinePreserveAliasTest extends TestCase
{
    public function testPreviousBodyIsPreservedUnderAliasAndCanBeRestored(): void
    {
        $class  = new ReflectionClass(Counter::class);
        $method = $class->getMethod('counter');

        $method->redefine((new ReflectionClass(CounterDonor::class))->getMethod('counter'), preserveAs: 'counterOriginal');

        $instance = new Counter();
        self::assertSame(101, $instance->counter());
        self::assertSame(102, $instance->counter(), 'the preserved body keeps its own static variable');

        $alias = new \ReflectionMethod(Counter::class, 'counterOriginal');
        self::assertTrue($alias->isPrivate());
        self::assertSame(Counter::class, $alias->getDeclaringClass()->getName());
        self::assertSame((new \ReflectionClass(Counter::class))->getFileName(), $alias->getFileName());

        // Restore the previous body from the alias, then drop the alias again
        $class = new ReflectionClass(Counter::class);
        $class->getMethod('counter')->redefine($class->getMethod('counterOriginal'));
        $class->removeMethods('counterOriginal');
        self::assertSame(1, (new Counter())->counter(), 'the restored body starts with fresh statics');
        self::assertFalse(method_exists(Counter::class, 'counterOriginal'));
    }

    public function testMethodDonorAdoptsBodyAndSignature(): void
    {
        $class = new ReflectionClass(Greeter::class);
        $donor = new ReflectionClass(GreeterDonor::class);
        $class->getMethod('greet')->redefine($donor->getMethod('greet'), preserveAs: 'greetOriginal');
        $class = new ReflectionClass(Greeter::class);
        $class->getMethod('itself')->redefine($donor->getMethod('itself'), preserveAs: 'itselfOriginal');

        $instance = new Greeter('a');
        self::assertSame('[hi x from a]', $instance->greet('x'));
        self::assertSame($instance, $instance->itself(), 'an explicit class type in the donor matches self of the entry');

        $woven = new \ReflectionMethod(Greeter::class, 'greet');
        self::assertTrue($woven->isPublic());
        self::assertSame('string', (string) $woven->getReturnType());
        self::assertSame(Greeter::class, $woven->getDeclaringClass()->getName());
        self::assertSame((new \ReflectionClass(GreeterDonor::class))->getFileName(), $woven->getFileName(), 'the body (and its file) follow the donor');
        self::assertSame((new \ReflectionClass(Greeter::class))->getFileName(), (new \ReflectionMethod(Greeter::class, 'greetOriginal'))->getFileName());
        self::assertTrue((new \ReflectionMethod(Greeter::class, 'greetOriginal'))->isPrivate());
    }

    public function testStaticMethodAliasKeepsLateStaticBinding(): void
    {
        $class = new ReflectionClass(Statics::class);
        $donor = new ReflectionClass(StaticsDonor::class);
        $class->getMethod('label')->redefine($donor->getMethod('label'), preserveAs: 'labelOriginal');

        self::assertSame('<label:' . Statics::class . ':s>', Statics::label('s'));
        self::assertSame('<label:' . StaticsChild::class . ':s>', StaticsChild::label('s'));
        self::assertTrue((new \ReflectionMethod(Statics::class, 'labelOriginal'))->isStatic());
        self::assertTrue((new \ReflectionMethod(Statics::class, 'labelOriginal'))->isPrivate());
    }

    public function testPrivateMethodAlias(): void
    {
        $class = new ReflectionClass(Secretive::class);
        $donor = new ReflectionClass(SecretiveDonor::class);
        $class->getMethod('secret')->redefine($donor->getMethod('secret'), preserveAs: 'secretOriginal');

        self::assertSame(86, (new Secretive())->reveal());
        self::assertTrue((new \ReflectionMethod(Secretive::class, 'secret'))->isPrivate(), 'declaration flags stay');
    }

    public function testConstructorAliasKeepsTheConstructorSlot(): void
    {
        $class = new ReflectionClass(Constructed::class);
        $donor = new ReflectionClass(ConstructedDonor::class);
        $class->getMethod('__construct')->redefine($donor->getMethod('__construct'), preserveAs: '__constructOriginal');

        Constructed::$log = [];
        $instance         = new Constructed('abc');
        self::assertSame('ABC', $instance->name);
        self::assertSame(['ctor:ABC'], Constructed::$log);
        self::assertSame('__construct', (new \ReflectionClass(Constructed::class))->getConstructor()?->getName());
        self::assertFalse((new \ReflectionMethod(Constructed::class, '__constructOriginal'))->isConstructor());
    }

    public function testChildAliasShadowsTheParentAliasWithTheChangedFlag(): void
    {
        $ancestor = new ReflectionClass(Ancestor::class);
        $ancestor->getMethod('greet')->redefine((new ReflectionClass(AncestorDonor::class))->getMethod('greet'), preserveAs: 'greetOriginal');

        // Autoloaded now, i.e. linked against the redefined ancestor: inherits the alias bucket
        self::assertSame('child:[hi q]', (new Descendant())->greet('q'));
        self::assertTrue((new \ReflectionClass(Descendant::class))->hasMethod('greetOriginal'));

        $descendant = new ReflectionClass(Descendant::class);
        $descendant->getMethod('greet')->redefine((new ReflectionClass(DescendantDonor::class))->getMethod('greet'), preserveAs: 'greetOriginal');

        // The ancestor's body runs on a Descendant receiver and must reach ITS alias
        self::assertSame('{child:[hi w]}', (new Descendant())->greet('w'));
        self::assertSame('{child:[hi g]}', (new GrandDescendant())->greet('g'));

        $childAlias = (new ReflectionClass(Descendant::class))->getMethod('greetOriginal');
        $flags      = $childAlias->getCommonPointer()->fn_flags;
        self::assertSame(Core::ZEND_ACC_CHANGED, $flags & Core::ZEND_ACC_CHANGED, 'a redeclared parent-private method is marked CHANGED');
        self::assertSame(Descendant::class, $childAlias->getDeclaringClass()->getName());
    }

    public function testAliasIsSharedIntoASubclassLinkedBefore(): void
    {
        // Linked now, before the redefinition
        self::assertSame('base', (new EarlySub())->m());
        $class = new ReflectionClass(EarlyBase::class);

        if ($class->isImmutable()) {
            // A subclass linked against the shared-memory entry could never see the alias
            $this->expectException(SharedMemoryException::class);
            $this->expectExceptionMessageMatches('/subclass .*EarlySub was linked against the shared entry/');
        }
        $class->getMethod('m')->redefine((new ReflectionClass(EarlyDonor::class))->getMethod('m'), preserveAs: 'mOriginal');

        self::assertSame('[base]', (new EarlyBase())->m());
        self::assertSame('[base]', (new EarlySub())->m(), 'the subclass inherited the alias after the fact');
    }

    public function testSecondAliasOnTheSameMethodIsRefused(): void
    {
        $class = new ReflectionClass(Twice::class);
        $class->getMethod('m')->redefine(fn(): int => 10, preserveAs: 'mOriginal');
        $instance = new Twice();
        self::assertSame(10, $instance->m());
        self::assertSame(1, (new \ReflectionMethod(Twice::class, 'mOriginal'))->invoke($instance), 'a closure donor preserves the body as well');

        $this->expectException(RedefinitionException::class);
        $this->expectExceptionMessageMatches('/already declares a method of that name/');
        (new ReflectionClass(Twice::class))->getMethod('m')->redefine(fn(): int => 0, preserveAs: 'mOriginal');
    }

    public function testDonorValidation(): void
    {
        $class = new ReflectionClass(Invalid::class);

        try {
            $class->getMethod('dyn')->redefine($class->getMethod('stat'));
            self::fail('static-ness mismatch not refused');
        } catch (RedefinitionException $exception) {
            self::assertStringContainsString('one is static and the other is not', $exception->getMessage());
        }
        try {
            $class->getMethod('dyn')->redefine((new ReflectionClass(InvalidAbstract::class))->getMethod('dyn'));
            self::fail('abstract donor not refused');
        } catch (RedefinitionException $exception) {
            self::assertStringContainsString('abstract method has no body', $exception->getMessage());
        }
        try {
            $class->getMethod('dyn')->redefine($class->getMethod('typed'));
            self::fail('signature mismatch not refused');
        } catch (RedefinitionException $exception) {
            self::assertStringContainsString('should be compatible with original', $exception->getMessage());
        }
        try {
            $class->getMethod('dyn')->redefine($class->getMethod('dyn'));
            self::fail('entry as its own donor not refused');
        } catch (RedefinitionException $exception) {
            self::assertStringContainsString('with its own body', $exception->getMessage());
        }
        try {
            $class->getMethod('dyn')->redefine(new ReflectionFunction('strlen'));
            self::fail('internal donor not refused');
        } catch (RedefinitionException $exception) {
            self::assertStringContainsString('internal function', $exception->getMessage());
        }
        try {
            $class->getMethod('dyn')->redefine(fn(): int => 0, preserveAs: 'not a name');
            self::fail('invalid alias name not refused');
        } catch (RedefinitionException $exception) {
            self::assertStringContainsString('not a valid function or method name', $exception->getMessage());
        }
        self::assertSame(1, (new Invalid())->dyn(), 'nothing was modified by the refused calls');
    }

    public function testGlobalFunctionAlias(): void
    {
        require_once __DIR__ . '/../Stub/Alias/functions.php';
        self::assertTrue(function_exists('zengine_alias_fixture_fn'));

        $function = new ReflectionFunction('zengine_alias_fixture_fn');
        $function->redefine(fn(int $x): int => $x * 10, preserveAs: 'zengine_alias_fixture_fn_original');

        self::assertSame(20, \zengine_alias_fixture_fn(2));
        self::assertTrue(function_exists('zengine_alias_fixture_fn_original'));
        self::assertSame(3, \zengine_alias_fixture_fn_original(2));

        $this->expectException(RedefinitionException::class);
        (new ReflectionFunction('zengine_alias_fixture_fn'))->redefine(fn(int $x): int => 0, preserveAs: 'strlen');
    }

    public function testAddMethodPublishesADonorBodyAsTheOverrideOfAnInheritedMethod(): void
    {
        $derived = new ReflectionClass(OverrideDerived::class);
        $donor   = new ReflectionClass(OverrideDonor::class);

        $published = $derived->addMethod('hello', $donor->getMethod('hello'));
        self::assertSame('hello', $published->getName());
        self::assertSame(OverrideDerived::class, $published->getDeclaringClass()->getName());

        self::assertSame('derived+base', (new OverrideDerived())->hello());
        self::assertSame('base', (new OverrideBase())->hello(), 'the ancestor is untouched');
        $native = new \ReflectionMethod(OverrideDerived::class, 'hello');
        self::assertSame(OverrideDerived::class, $native->getDeclaringClass()->getName());
        self::assertSame(OverrideBase::class, $native->getPrototype()->getDeclaringClass()->getName());

        // Autoloaded now, i.e. linked against the class with the override
        self::assertSame('derived+base', (new OverrideDerivedSub())->hello());

        // An inherited constructor can be overridden the same way: the constructor slot follows
        $derived = new ReflectionClass(OverrideDerived::class);
        $derived->addMethod('__construct', $donor->getMethod('__construct'));
        self::assertSame('derived-x', (new OverrideDerived('x'))->tag);
        self::assertSame('derived-x', (new OverrideDerivedSub('x'))->tag);
        self::assertSame(OverrideDerived::class, (new \ReflectionClass(OverrideDerived::class))->getConstructor()?->getDeclaringClass()->getName());
        self::assertSame('base', (new OverrideBase())->tag);
    }

    public function testAddMethodRefusesFinalOverridesAndDeclaredMethods(): void
    {
        $derived = new ReflectionClass(OverrideDerived::class);
        $donor   = new ReflectionClass(OverrideDonor::class);

        try {
            $derived->addMethod('sealed', (new ReflectionClass(OverrideSealedDonor::class))->getMethod('sealed'));
            self::fail('final override not refused');
        } catch (RedefinitionException $exception) {
            self::assertStringContainsString('override the final method', $exception->getMessage());
        }
        try {
            $derived->addMethod('own', $donor->getMethod('own'));
            self::fail('declared method not refused');
        } catch (RedefinitionException $exception) {
            self::assertStringContainsString('already declares that method', $exception->getMessage());
        }
        self::assertSame('sealed', (new OverrideDerived())->sealed());
        self::assertSame('derived-own', (new OverrideDerived())->own());
    }

    public function testOverridePropagatesToASubclassLinkedBefore(): void
    {
        // The whole chain is linked now, before the override is published on the middle class
        self::assertSame('base', (new EarlyOverrideLeaf())->m());
        $middle = new ReflectionClass(EarlyOverrideMid::class);

        if ($middle->isImmutable()) {
            $this->expectException(SharedMemoryException::class);
            $this->expectExceptionMessageMatches('/subclass .*EarlyOverrideLeaf was linked against the shared entry/');
        }
        $middle->addMethod('m', (new ReflectionClass(EarlyOverrideDonor::class))->getMethod('m'));

        self::assertSame('over+base', (new EarlyOverrideMid())->m());
        self::assertSame('over+base', (new EarlyOverrideLeaf())->m(), 'the leaf linked before follows the override');
        self::assertSame('base', (new EarlyOverrideBase())->m());
    }

    public function testInheritedMethodInterceptionThroughParentCall(): void
    {
        // Descendant inherits inheritedOnly() from Ancestor; the override donor reaches the
        // ancestor body through parent::, which resolves against Descendant at run time
        $descendant = new ReflectionClass(Descendant::class);
        $descendant->addMethod('inheritedOnly', (new ReflectionClass(DescendantOverrideDonor::class))->getMethod('inheritedOnly'));

        self::assertSame(1002, (new Descendant())->inheritedOnly(1));
        self::assertSame(2, (new Ancestor())->inheritedOnly(1));
        self::assertSame(1002, (new GrandDescendant())->inheritedOnly(1), 'subclasses linked after the parent redefinition inherit the override');
    }
}
