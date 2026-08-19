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

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZEngine\Stub\TestClass;
use ZEngine\Stub\TestSlotSpecializationTemplate;

/**
 * Covers slot-addressed type substitution: rewriting a declaration the name-keyed
 * TypeSubstitutionMap cannot reach because it carries a builtin type such as `mixed`
 * (see docs/class-specialization.md).
 *
 * Registered specialized classes stay in the class table for the rest of the process, so
 * every test that registers one uses a unique target name.
 */
class ClassSpecializerSlotTest extends TestCase
{
    private const PLACEHOLDER = 'ZEngine\Stub\TPlaceholder';

    public function testMixedPropertyBecomesABuiltinType(): void
    {
        $newName = 'ZEngine\Stub\Specialized\SlotPropertyCopy';
        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, $newName, null, new SlotSubstitutionMap([
            [TypeSlot::property('value'), 'int'],
        ]));

        self::assertSame('int', (string) (new \ReflectionProperty($newName, 'value'))->getType());

        $instance        = new $newName();
        $instance->value = 42;
        self::assertSame(42, $instance->value);

        // The template keeps `mixed`, so it still accepts anything at all
        self::assertSame('mixed', (string) (new \ReflectionProperty(TestSlotSpecializationTemplate::class, 'value'))->getType());
        $original        = new TestSlotSpecializationTemplate();
        $original->value = 'anything';
        self::assertSame('anything', $original->value);

        $this->expectException(\TypeError::class);
        $instance->value = 'not an int';
    }

    public function testMixedPropertyBecomesAClassType(): void
    {
        $newName = 'ZEngine\Stub\Specialized\SlotClassPropertyCopy';
        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, $newName, null, new SlotSubstitutionMap([
            [TypeSlot::property('value'), '\\' . TestClass::class],
        ]));

        self::assertSame(TestClass::class, (string) (new \ReflectionProperty($newName, 'value'))->getType());

        $instance        = new $newName();
        $instance->value = new TestClass();
        // Read back through reflection: the slot itself is what the specializer rewrote
        self::assertInstanceOf(TestClass::class, (new \ReflectionProperty($newName, 'value'))->getValue($instance));

        $this->expectException(\TypeError::class);
        $instance->value = 42;
    }

    public function testNullabilityComesFromTheReplacement(): void
    {
        $newName = 'ZEngine\Stub\Specialized\SlotNullableCopy';
        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, $newName, null, new SlotSubstitutionMap([
            [TypeSlot::property('nullableValue'), '?int'],
            [TypeSlot::property('value'), 'string'],
        ]));

        $nullable = (new \ReflectionProperty($newName, 'nullableValue'))->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $nullable);
        self::assertTrue($nullable->allowsNull());

        // `mixed` implies null; a non-nullable replacement must not inherit that
        $plain = (new \ReflectionProperty($newName, 'value'))->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $plain);
        self::assertFalse($plain->allowsNull());

        $instance                = new $newName();
        $instance->nullableValue = null;
        self::assertNull($instance->nullableValue);
        $instance->nullableValue = 7;
        self::assertSame(7, $instance->nullableValue);

        $this->expectException(\TypeError::class);
        $instance->nullableValue = 'text';
    }

    public function testParameterAndReturnTypesAreAddressedIndependently(): void
    {
        $newName = 'ZEngine\Stub\Specialized\SlotSignatureCopy';
        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, $newName, null, new SlotSubstitutionMap([
            [TypeSlot::parameter('setNamed', 0), 'int'],
            [TypeSlot::returnType('getNamed'), '?int'],
            [TypeSlot::property('named'), '?int'],
        ]));

        self::assertSame('int', (string) (new \ReflectionMethod($newName, 'setNamed'))->getParameters()[0]->getType());
        self::assertSame('?int', (string) (new \ReflectionMethod($newName, 'getNamed'))->getReturnType());

        $instance = new $newName();
        $instance->setNamed(11);
        self::assertSame(11, $instance->getNamed());

        $this->expectException(\TypeError::class);
        $instance->setNamed('not an int');
    }

    // TODO(#131): once literals are copied alongside opcodes the 2GB IS_CONST reach limit
    // disappears and this test returns to the opcache-active runner job. Until then a
    // shared-memory method body always refuses slot substitution (the template class is
    // compiled into SHM when the runner itself has opcache active), so the substitution
    // this test asserts cannot happen there by design.
    #[Group('opcache-incompatible')]
    public function testBuiltinTypedParameterIsRewrittenAndEnforced(): void
    {
        $newName = 'ZEngine\Stub\Specialized\SlotBuiltinParamCopy';
        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, $newName, null, new SlotSubstitutionMap([
            [TypeSlot::parameter('setValue', 0), 'int'],
        ]));

        self::assertSame('int', (string) (new \ReflectionMethod($newName, 'setValue'))->getParameters()[0]->getType());

        $instance = new $newName();
        $instance->setValue(42);
        self::assertSame(42, $instance->value);

        // The template shares nothing writable with the copy: its cached RECV mask is untouched
        $original = new TestSlotSpecializationTemplate();
        $original->setValue('anything at all');
        self::assertSame('anything at all', $original->value);

        $this->expectException(\TypeError::class);
        $instance->setValue('not an int');
    }

    public function testBuiltinTypedReturnIsRewrittenAndEnforced(): void
    {
        $newName = 'ZEngine\Stub\Specialized\SlotBuiltinReturnCopy';
        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, $newName, null, new SlotSubstitutionMap([
            [TypeSlot::returnType('describeValue'), 'int'],
        ]));

        self::assertSame('int', (string) (new \ReflectionMethod($newName, 'describeValue'))->getReturnType());

        // The return check reads arg_info on every call, so no opcode work was needed here
        $instance        = new $newName();
        $instance->value = 'a string';
        $this->expectException(\TypeError::class);
        $instance->describeValue();
    }

    /**
     * A `mixed` return type emits no ZEND_VERIFY_RETURN_TYPE on the real return path at all, so
     * there is nothing to make the substitution take effect - rejected rather than left silent.
     */
    public function testReturnTypeWithoutACheckOpcodeIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('left at least one return statement unchecked');

        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, 'ZEngine\Stub\Specialized\SlotRejectedA', null, new SlotSubstitutionMap([
            [TypeSlot::returnType('getValue'), 'int'],
        ]));
    }

    /**
     * The other elision: the compiler proved `return 'literal';` satisfies `string`, so it never
     * emitted a check there either - and a substitution would go unenforced on that path.
     */
    public function testConstantFoldedReturnCheckIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('left at least one return statement unchecked');

        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, 'ZEngine\Stub\Specialized\SlotRejectedB', null, new SlotSubstitutionMap([
            [TypeSlot::returnType('constantReturn'), 'int'],
        ]));
    }

    // TODO(#131): same 2GB IS_CONST reach refusal as testBuiltinTypedParameterIsRewrittenAndEnforced
    #[Group('opcache-incompatible')]
    public function testOpcodeCopyPreservesLiteralsAndJumps(): void
    {
        $newName = 'ZEngine\Stub\Specialized\SlotOpcodeRelocationCopy';
        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, $newName, null, new SlotSubstitutionMap([
            [TypeSlot::parameter('classify', 0), 'int'],
        ]));

        // classify() has a loop, a try/catch and string literals: if the IS_CONST rebase or the
        // jump offsets were wrong, this would read the wrong zval or jump into the middle of an
        // opline rather than return the expected strings
        $instance = new $newName();
        self::assertSame('negative', $instance->classify(-3));
        self::assertSame('zero', $instance->classify(0));
        self::assertSame('positive:3', $instance->classify(3));

        $original = new TestSlotSpecializationTemplate();
        self::assertSame('positive:2', $original->classify(2));

        $this->expectException(\TypeError::class);
        $instance->classify('not an int');
    }

    public function testMethodNamesAreMatchedCaseInsensitively(): void
    {
        $newName = 'ZEngine\Stub\Specialized\SlotCaseCopy';
        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, $newName, null, new SlotSubstitutionMap([
            [TypeSlot::parameter('SETNAMED', 0), 'string'],
        ]));

        self::assertSame('string', (string) (new \ReflectionMethod($newName, 'setNamed'))->getParameters()[0]->getType());
    }

    public function testVariadicParameterIsAddressable(): void
    {
        $newName = 'ZEngine\Stub\Specialized\SlotVariadicCopy';
        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, $newName, null, new SlotSubstitutionMap([
            [TypeSlot::parameter('collect', 0), 'int'],
        ]));

        self::assertSame('int', (string) (new \ReflectionMethod($newName, 'collect'))->getParameters()[0]->getType());

        $instance = new $newName();
        self::assertSame(3, $instance->collect(1, 2, 3));

        $this->expectException(\TypeError::class);
        $instance->collect(1, 'two');
    }

    public function testSlotSubstitutionWinsOverANameSubstitution(): void
    {
        $newName = 'ZEngine\Stub\Specialized\SlotPrecedenceCopy';
        (new ClassSpecializer())->specialize(
            TestSlotSpecializationTemplate::class,
            $newName,
            new TypeSubstitutionMap([self::PLACEHOLDER => 'array']),
            new SlotSubstitutionMap([
                [TypeSlot::property('named'), 'int'],
                [TypeSlot::property('value'), 'string'],
            ]),
        );

        // The slot map replaced the placeholder-typed declaration outright...
        self::assertSame('int', (string) (new \ReflectionProperty($newName, 'named'))->getType());
        // ...while the name map still applied to nothing else that mentioned it
        self::assertSame('string', (string) (new \ReflectionProperty($newName, 'value'))->getType());
    }

    public function testEachCopyGetsItsOwnArgInfoBlock(): void
    {
        $first  = 'ZEngine\Stub\Specialized\SlotIsolationFirstCopy';
        $second = 'ZEngine\Stub\Specialized\SlotIsolationSecondCopy';
        $slots  = static fn(string $type): SlotSubstitutionMap => new SlotSubstitutionMap([
            [TypeSlot::parameter('setNamed', 0), $type],
        ]);

        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, $first, null, $slots('int'));
        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, $second, null, $slots('string'));

        self::assertSame('int', (string) (new \ReflectionMethod($first, 'setNamed'))->getParameters()[0]->getType());
        self::assertSame('string', (string) (new \ReflectionMethod($second, 'setNamed'))->getParameters()[0]->getType());
        // The block the template still shares with its own body was never written into
        self::assertSame(
            self::PLACEHOLDER,
            (string) (new \ReflectionMethod(TestSlotSpecializationTemplate::class, 'setNamed'))->getParameters()[0]->getType(),
        );
    }

    public function testUnknownPropertyIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('no such property');

        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, 'ZEngine\Stub\Specialized\SlotRejected1', null, new SlotSubstitutionMap([
            [TypeSlot::property('missing'), 'int'],
        ]));
    }

    public function testUnknownMethodIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('no such method');

        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, 'ZEngine\Stub\Specialized\SlotRejected2', null, new SlotSubstitutionMap([
            [TypeSlot::returnType('missing'), 'int'],
        ]));
    }

    public function testInheritedSlotIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('inherited property');

        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, 'ZEngine\Stub\Specialized\SlotRejected3', null, new SlotSubstitutionMap([
            [TypeSlot::property('inheritedValue'), 'int'],
        ]));
    }

    public function testMissingReturnTypeIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('declares no return type');

        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, 'ZEngine\Stub\Specialized\SlotRejected4', null, new SlotSubstitutionMap([
            [TypeSlot::returnType('withoutReturnType'), 'int'],
        ]));
    }

    public function testUntypedParameterIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('no type to replace');

        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, 'ZEngine\Stub\Specialized\SlotRejected5', null, new SlotSubstitutionMap([
            [TypeSlot::parameter('untypedParameter', 0), 'int'],
        ]));
    }

    public function testUnionSlotIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('union/intersection type');

        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, 'ZEngine\Stub\Specialized\SlotRejected6', null, new SlotSubstitutionMap([
            [TypeSlot::returnType('unionReturn'), 'int'],
        ]));
    }

    public function testParameterIndexOutOfRangeIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('declares only 1 parameter(s)');

        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, 'ZEngine\Stub\Specialized\SlotRejected7', null, new SlotSubstitutionMap([
            [TypeSlot::parameter('setNamed', 3), 'int'],
        ]));
    }

    public function testIncompatibleDefaultValueIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('default value would no longer satisfy the type');

        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, 'ZEngine\Stub\Specialized\SlotRejected8', null, new SlotSubstitutionMap([
            [TypeSlot::property('textDefault'), 'int'],
        ]));
    }

    public function testNullDefaultRequiresANullableReplacement(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('default value would no longer satisfy the type');

        (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, 'ZEngine\Stub\Specialized\SlotRejected9', null, new SlotSubstitutionMap([
            [TypeSlot::property('nullableValue'), 'int'],
        ]));
    }

    public function testEmptyReplacementIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('non-empty replacement type name');

        new SlotSubstitutionMap([[TypeSlot::property('value'), '']]);
    }

    public function testDuplicateSlotIsRejected(): void
    {
        $this->expectException(ClassSpecializationException::class);
        $this->expectExceptionMessage('Duplicate slot substitution');

        new SlotSubstitutionMap([
            [TypeSlot::parameter('setNamed', 0), 'int'],
            [TypeSlot::parameter('SETNAMED', 0), 'string'],
        ]);
    }

    public function testRejectionLeavesTheClassTableUntouched(): void
    {
        $newName = 'ZEngine\Stub\Specialized\SlotNeverRegistered';

        try {
            (new ClassSpecializer())->specialize(TestSlotSpecializationTemplate::class, $newName, null, new SlotSubstitutionMap([
                [TypeSlot::property('value'), 'int'],
                [TypeSlot::property('missing'), 'int'],
            ]));
            self::fail('An unknown slot must be rejected');
        } catch (ClassSpecializationException) {
            self::assertFalse(class_exists($newName, false));
        }
    }
}
