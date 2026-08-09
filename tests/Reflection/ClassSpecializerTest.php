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
use ZEngine\Stub\TestClass;
use ZEngine\Stub\TestHookedClass;
use ZEngine\Stub\TestIntBackedEnum;
use ZEngine\Stub\TestInterface;
use ZEngine\Stub\TestSpecializationBase;
use ZEngine\Stub\TestSpecializationChildOfPlaceholderBase;
use ZEngine\Stub\TestSpecializationCollection;
use ZEngine\Stub\TestSpecializationTemplate;
use ZEngine\Stub\TestSpecializationUnionTemplate;
use ZEngine\Stub\TestStubAttribute;
use ZEngine\Stub\TestTrait;

/**
 * Covers runtime class-entry specialization: deep-cloning a userland class under a new
 * name with a type-substitution pass (see docs/class-specialization.md).
 *
 * Registered specialized classes stay in the class table for the rest of the process,
 * so every test that registers one uses a unique target name.
 */
class ClassSpecializerTest extends TestCase
{
    private const PLACEHOLDER = 'ZEngine\Stub\TPlaceholder';

    public function testSpecializeRegistersIndependentClass(): void
    {
        $newName     = 'ZEngine\Stub\Specialized\BasicCopy';
        $template    = new ReflectionClass(TestSpecializationTemplate::class);
        $specialized = $template->specialize($newName, new TypeSubstitutionMap([
            self::PLACEHOLDER => 'int',
        ]));

        $this->assertSame($newName, $specialized->getName());
        $this->assertTrue(class_exists($newName, false));
        $this->assertTrue(class_exists(TestSpecializationTemplate::class, false));

        // The copy is a sibling of the template: same parent, same interfaces
        $parentClass = $specialized->getParentClass();
        $this->assertNotNull($parentClass);
        $this->assertSame(TestSpecializationBase::class, $parentClass->getName());
        $this->assertContains(TestInterface::class, $specialized->getInterfaceNames());
    }

    public function testInstancesDispatchIndependently(): void
    {
        $newName = 'ZEngine\Stub\Specialized\DispatchCopy';
        (new ClassSpecializer())->specialize(TestSpecializationTemplate::class, $newName, new TypeSubstitutionMap([
            self::PLACEHOLDER => 'int',
        ]));

        $instance = new $newName(3);
        $this->assertSame($newName, get_class($instance));
        $this->assertInstanceOf(TestSpecializationBase::class, $instance);
        $this->assertInstanceOf(TestInterface::class, $instance);
        // A sibling copy, not a subclass of the template (the name is read back through
        // the engine so the runtime-only relation is not constant-folded away)
        $templateName = (new ReflectionClass(TestSpecializationTemplate::class))->getName();
        $this->assertFalse(is_a($instance, $templateName));

        // Method dispatch and late static binding resolve against the copy
        $this->assertSame($newName . ':3', $instance->describe());
        $this->assertSame($newName, $newName::whoAmI());
        $this->assertSame('inherited:' . $newName, $instance->inheritedMethod());

        // The original class still dispatches against itself
        $original = new TestSpecializationTemplate(7);
        $this->assertSame(TestSpecializationTemplate::class . ':7', $original->describe());
        $this->assertSame(TestSpecializationTemplate::class, TestSpecializationTemplate::whoAmI());
    }

    public function testTypedPropertyEnforcementFollowsSubstitutedType(): void
    {
        $newName = 'ZEngine\Stub\Specialized\TypedPropertyCopy';
        (new ClassSpecializer())->specialize(TestSpecializationTemplate::class, $newName, new TypeSubstitutionMap([
            self::PLACEHOLDER => 'int',
        ]));

        // Substituted type is visible through native reflection...
        $propertyType = (new \ReflectionProperty($newName, 'value'))->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $propertyType);
        $this->assertSame('int', $propertyType->getName());

        // ...and enforced by the engine on the copy
        $instance = new $newName();
        $this->writeProperty($instance, 'value', 42);
        $this->assertSame(42, $instance->value);
        try {
            $this->writeProperty($instance, 'value', 'not an int');
            $this->fail('Assigning a string to the int-substituted property must throw TypeError');
        } catch (\TypeError $e) {
            $this->assertStringContainsString('int', $e->getMessage());
        }

        // The template keeps its placeholder type: the placeholder is not a real class,
        // so no value is assignable at all
        $originalType = (new \ReflectionProperty(TestSpecializationTemplate::class, 'value'))->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $originalType);
        $this->assertSame(self::PLACEHOLDER, $originalType->getName());
        $original = new TestSpecializationTemplate();
        $this->expectException(\TypeError::class);
        $this->writeProperty($original, 'value', 42);
    }

    /**
     * Writes an object property outside the analyser's sight: the specialized classes
     * only exist at runtime, so the engine-enforced (substituted) property types cannot
     * be expressed statically
     */
    private function writeProperty(object $target, string $property, mixed $value): void
    {
        $target->{$property} = $value;
    }

    public function testMethodSignaturesFollowSubstitutedTypes(): void
    {
        $newName = 'ZEngine\Stub\Specialized\MethodSignatureCopy';
        (new ClassSpecializer())->specialize(TestSpecializationTemplate::class, $newName, new TypeSubstitutionMap([
            self::PLACEHOLDER => 'int',
        ]));

        $parameterType = (new \ReflectionMethod($newName, 'setValue'))->getParameters()[0]->getType();
        $returnType    = (new \ReflectionMethod($newName, 'getValue'))->getReturnType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $parameterType);
        $this->assertInstanceOf(\ReflectionNamedType::class, $returnType);
        $this->assertSame('int', $parameterType->getName());
        $this->assertSame('int', $returnType->getName());

        // Runtime enforcement on the copy: int accepted, string rejected
        $instance = new $newName();
        $instance->setValue(42);
        $this->assertSame(42, $instance->getValue());
        try {
            $instance->setValue('not an int');
            $this->fail('Passing a string to the int-substituted parameter must throw TypeError');
        } catch (\TypeError $e) {
            $this->assertStringContainsString('int', $e->getMessage());
        }

        // The template's method signature is untouched
        $originalParameter = (new \ReflectionMethod(TestSpecializationTemplate::class, 'setValue'))
            ->getParameters()[0]->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $originalParameter);
        $this->assertSame(self::PLACEHOLDER, $originalParameter->getName());
    }

    public function testClassNameSubstitutionTarget(): void
    {
        $newName = 'ZEngine\Stub\Specialized\ClassTargetCopy';
        (new ClassSpecializer())->specialize(TestSpecializationTemplate::class, $newName, new TypeSubstitutionMap([
            self::PLACEHOLDER => TestClass::class,
        ]));

        $propertyType = (new \ReflectionProperty($newName, 'value'))->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $propertyType);
        $this->assertSame(TestClass::class, $propertyType->getName());

        $instance = new $newName();
        $payload  = new TestClass();
        $instance->setValue($payload);
        $this->assertSame($payload, $instance->getValue());
        $this->expectException(\TypeError::class);
        $this->writeProperty($instance, 'value', 42);
    }

    public function testStaticPropertiesAreIndependent(): void
    {
        $newName = 'ZEngine\Stub\Specialized\StaticStateCopy';
        (new ClassSpecializer())->specialize(TestSpecializationTemplate::class, $newName, new TypeSubstitutionMap([
            self::PLACEHOLDER => 'int',
        ]));

        $originalCountBefore    = TestSpecializationTemplate::$instances;
        $specializedCountBefore = $newName::$instances;

        new $newName();
        new $newName();

        $this->assertSame($specializedCountBefore + 2, $newName::$instances);
        $this->assertSame($originalCountBefore, TestSpecializationTemplate::$instances);

        new TestSpecializationTemplate();
        $this->assertSame($originalCountBefore + 1, TestSpecializationTemplate::$instances);
        $this->assertSame($specializedCountBefore + 2, $newName::$instances);
    }

    public function testConstantsAndAttributesAreCarriedOver(): void
    {
        $newName = 'ZEngine\Stub\Specialized\ConstantsCopy';
        (new ClassSpecializer())->specialize(TestSpecializationTemplate::class, $newName, new TypeSubstitutionMap([
            self::PLACEHOLDER => 'int',
        ]));

        $this->assertSame('template', constant($newName . '::TEMPLATE_CONST'));
        $this->assertSame(10, constant($newName . '::BASE_CONST'));

        $attributes = (new \ReflectionClass($newName))->getAttributes();
        $this->assertCount(1, $attributes);
        $this->assertSame(TestStubAttribute::class, $attributes[0]->getName());
        $this->assertSame(['specialization', 42], array_values($attributes[0]->getArguments()));
    }

    public function testSpecializationWithoutSubstitutionsKeepsPlaceholder(): void
    {
        $newName = 'ZEngine\Stub\Specialized\PlainCopy';
        (new ClassSpecializer())->specialize(TestSpecializationTemplate::class, $newName);

        $propertyType = (new \ReflectionProperty($newName, 'value'))->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $propertyType);
        $this->assertSame(self::PLACEHOLDER, $propertyType->getName());

        $instance = new $newName(9);
        $this->assertSame($newName . ':9', $instance->describe());
    }

    public function testInternalClassIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('internal class');
        (new ClassSpecializer())->specialize(\ArrayObject::class, 'ZEngine\Stub\Specialized\NeverInternal');
    }

    public function testEnumIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('enum');
        (new ClassSpecializer())->specialize(TestIntBackedEnum::class, 'ZEngine\Stub\Specialized\NeverEnum');
    }

    public function testInterfaceIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('interface');
        (new ClassSpecializer())->specialize(TestInterface::class, 'ZEngine\Stub\Specialized\NeverInterface');
    }

    public function testTraitIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('trait');
        (new ClassSpecializer())->specialize(TestTrait::class, 'ZEngine\Stub\Specialized\NeverTrait');
    }

    public function testPropertyHookedClassIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('property hooks');
        (new ClassSpecializer())->specialize(TestHookedClass::class, 'ZEngine\Stub\Specialized\NeverHooked');
    }

    public function testUnknownSourceClassIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('was not found');
        (new ClassSpecializer())->specialize('ZEngine\Stub\DoesNotExist', 'ZEngine\Stub\Specialized\NeverUnknown');
    }

    public function testDuplicateTargetNameIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('already exists');
        (new ClassSpecializer())->specialize(
            TestSpecializationTemplate::class,
            TestSpecializationBase::class,
            new TypeSubstitutionMap([self::PLACEHOLDER => 'int']),
        );
    }

    public function testSubstitutingInheritedPlaceholderIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('inherited');
        (new ClassSpecializer())->specialize(
            TestSpecializationChildOfPlaceholderBase::class,
            'ZEngine\Stub\Specialized\NeverInheritedPlaceholder',
            new TypeSubstitutionMap([self::PLACEHOLDER => 'int']),
        );
    }

    public function testSubstitutingInsideUnionTypeIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('union/intersection');
        (new ClassSpecializer())->specialize(
            TestSpecializationUnionTemplate::class,
            'ZEngine\Stub\Specialized\NeverUnion',
            new TypeSubstitutionMap([self::PLACEHOLDER => 'int']),
        );
    }

    public function testUnionTypeWithoutSubstitutionIsCopied(): void
    {
        $newName = 'ZEngine\Stub\Specialized\UnionCopy';
        (new ClassSpecializer())->specialize(TestSpecializationUnionTemplate::class, $newName);

        $unionType = (new \ReflectionProperty($newName, 'union'))->getType();
        $this->assertInstanceOf(\ReflectionUnionType::class, $unionType);
        $names = array_map(
            static function (\ReflectionType $type): string {
                assert($type instanceof \ReflectionNamedType);

                return $type->getName();
            },
            $unionType->getTypes(),
        );
        $this->assertEqualsCanonicalizing([self::PLACEHOLDER, TestClass::class], $names);

        $instance        = new $newName();
        $instance->union = new TestClass();
        $this->assertInstanceOf(TestClass::class, $instance->union);
    }

    public function testInvalidSubstitutionMapIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        new TypeSubstitutionMap(['T' => '']);
    }

    public function testIteratorAndArrayAccessCachesAreRetargeted(): void
    {
        $newName = 'ZEngine\Stub\Specialized\CollectionCopy';
        // Warm the source's engine iterator path first, then specialize
        $source = new TestSpecializationCollection();
        $this->assertSame(TestSpecializationCollection::class . ':1', iterator_to_array($source)[0]);

        (new ClassSpecializer())->specialize(TestSpecializationCollection::class, $newName);

        $copy = new $newName();
        // foreach drives ce->get_iterator through the copied zend_class_iterator_funcs
        $this->assertSame(
            [$newName . ':1', $newName . ':2', $newName . ':3'],
            iterator_to_array($copy),
        );
        // Countable and ArrayAccess dispatch through the copied method table
        $this->assertCount(3, $copy);
        $this->assertSame(2, $copy[1]);
        $copy[] = 4;
        $this->assertCount(4, $copy);
        unset($copy[3]);
        $this->assertTrue(isset($copy[0]));

        // The source keeps dispatching against its own entries
        $this->assertSame(TestSpecializationCollection::class . ':3', iterator_to_array($source)[2]);
        $this->assertCount(3, $source);
    }

    public function testSpecializedClassSurvivesExplicitEngineTeardown(): void
    {
        $newName = 'ZEngine\Stub\Specialized\TeardownCopy';
        (new ClassSpecializer())->specialize(TestSpecializationTemplate::class, $newName, new TypeSubstitutionMap([
            self::PLACEHOLDER => 'int',
        ]));
        $instance = new $newName(4);
        $instance->setValue(11);
        $this->assertSame(11, $instance->getValue());
        unset($instance);

        // Evicting runs destroy_zend_class() with refcount 1: the full user-class
        // teardown (tables, own infos/constants, owned names) is exercised NOW
        // instead of at request shutdown
        $this->assertTrue((new ClassSpecializer())->evict($newName));
        $this->assertFalse(class_exists($newName, false));

        // The template stays fully intact: shared bodies, names and types survived
        $original = new TestSpecializationTemplate(6);
        $this->assertSame(TestSpecializationTemplate::class . ':6', $original->describe());
        $originalType = (new \ReflectionProperty(TestSpecializationTemplate::class, 'value'))->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $originalType);
        $this->assertSame(self::PLACEHOLDER, $originalType->getName());
    }

    public function testEvictingAnUnknownNameReportsFalse(): void
    {
        $this->assertFalse((new ClassSpecializer())->evict('ZEngine\Stub\Specialized\NeverRegistered'));
    }

    public function testEvictingAnInternalClassIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('Cannot evict internal class');
        (new ClassSpecializer())->evict(\SplStack::class);
    }
}
