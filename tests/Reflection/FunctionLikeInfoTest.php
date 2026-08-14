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

use PHPUnit\Framework\TestCase;
use ZEngine\Type\ArgumentEntry;
use ZEngine\Type\ClosureEntry;
use ZEngine\Type\LiveRange;

/**
 * Functions to reflect: argument info entries
 */
function argumentInfoFunction(int $first, string &$second, ?\stdClass $third = null, int ...$rest): ?string
{
    return $rest === [] ? null : (string) count($rest);
}

/**
 * Deliberately untyped: the engine stores an empty type mask for it
 *
 * @param mixed $value
 * @return mixed
 */
function untypedInfoFunction($value)
{
    return $value;
}

/**
 * CV slot layout fixture: declared arguments occupy the first slots in declaration
 * order, then plain locals in order of first appearance ($sum before $conditional,
 * which is compiled into a slot even though its assignment never executes)
 *
 * @return array{int, string, string|null}
 */
function localVariablesFunction(int $first, string $second = 'default'): array
{
    $sum = $first + 1;
    if ($first < 0) {
        $conditional = 'assigned only for negative input';
    }

    return [$sum, $second, $conditional ?? null];
}

function staticVariablesFunction(): int
{
    /** @var int $invocations */
    static $invocations = 0;

    return ++$invocations;
}

function tryCatchFinallyFunction(bool $shouldThrow = false): string
{
    try {
        if ($shouldThrow) {
            throw new \RuntimeException('Requested by the caller');
        }

        return 'try';
    } catch (\RuntimeException $e) {
        return 'catch';
    } finally {
        clearstatcache();
    }
}

function tryFinallyFunction(): string
{
    try {
        return 'try';
    } finally {
        clearstatcache();
    }
}

/**
 * @param list<int|string> $items
 */
function liveRangeFunction(array $items): int
{
    $sum = 0;
    foreach ($items as $item) {
        $sum += (int) $item;
    }

    return $sum;
}

class FunctionLikeInfoTest extends TestCase
{
    public function testUserFunctionArgumentInfoMatchesNativeReflection(): void
    {
        $refFunction    = new ReflectionFunction(__NAMESPACE__ . '\argumentInfoFunction');
        $nativeFunction = new \ReflectionFunction(__NAMESPACE__ . '\argumentInfoFunction');

        foreach ($nativeFunction->getParameters() as $nativeParameter) {
            $entry = $refFunction->getArgumentInfo($nativeParameter->getPosition());
            $this->assertSame($nativeParameter->getPosition(), $entry->getIndex());
            $this->assertSame($nativeParameter->getName(), $entry->getName());
            $this->assertSame($nativeParameter->isPassedByReference(), $entry->isByReference());
            $this->assertSame($nativeParameter->isVariadic(), $entry->isVariadic());
            $this->assertFalse($entry->isReturnEntry());
        }
    }

    public function testUserFunctionArgumentTypeMasks(): void
    {
        $refFunction = new ReflectionFunction(__NAMESPACE__ . '\argumentInfoFunction');

        $first = $refFunction->getArgumentInfo(0);
        $this->assertTrue($first->mayBeOfType(ReflectionValue::IS_LONG));
        $this->assertFalse($first->mayBeOfType(ReflectionValue::IS_STRING));
        $this->assertFalse($first->allowsNull());
        $this->assertSame(ArgumentEntry::SEND_BY_VAL, $first->getSendMode());

        $second = $refFunction->getArgumentInfo(1);
        $this->assertTrue($second->mayBeOfType(ReflectionValue::IS_STRING));
        $this->assertSame(ArgumentEntry::SEND_BY_REF, $second->getSendMode());

        // A class type is stored as a name reference, not as a MAY_BE_* bit: only the
        // nullability of ?\stdClass is visible in the pure mask
        $third = $refFunction->getArgumentInfo(2);
        $this->assertTrue($third->allowsNull());
        $this->assertFalse($third->mayBeOfType(ReflectionValue::IS_OBJECT));

        $variadic = $refFunction->getArgumentInfo(3);
        $this->assertTrue($variadic->isVariadic());
        $this->assertTrue($variadic->mayBeOfType(ReflectionValue::IS_LONG));
    }

    public function testUserFunctionReturnTypeEntry(): void
    {
        $refFunction = new ReflectionFunction(__NAMESPACE__ . '\argumentInfoFunction');

        $returnEntry = $refFunction->getArgumentInfo(ArgumentEntry::RETURN_ENTRY_INDEX);
        $this->assertTrue($returnEntry->isReturnEntry());
        $this->assertNull($returnEntry->getName());
        $this->assertTrue($returnEntry->mayBeOfType(ReflectionValue::IS_STRING));
        $this->assertTrue($returnEntry->allowsNull());
    }

    public function testArgumentInfoBoundsAreChecked(): void
    {
        $refFunction = new ReflectionFunction(__NAMESPACE__ . '\untypedInfoFunction');

        $entry = $refFunction->getArgumentInfo(0);
        $this->assertSame('value', $entry->getName());
        $this->assertSame(0, $entry->getPureTypeMask(), 'Untyped parameter should have no MAY_BE bits');
        $this->assertFalse($entry->isByReference());

        // No declared return type: the -1 return entry does not exist
        try {
            $refFunction->getArgumentInfo(ArgumentEntry::RETURN_ENTRY_INDEX);
            $this->fail('Expected an OutOfBoundsException for the missing return entry');
        } catch (\OutOfBoundsException $e) {
            $this->assertStringContainsString('out of bounds', $e->getMessage());
        }

        $this->expectException(\OutOfBoundsException::class);
        $refFunction->getArgumentInfo(1);
    }

    public function testInternalFunctionArgumentInfoMatchesNativeReflection(): void
    {
        // str_replace has a by-ref output parameter, sprintf a variadic one
        foreach (['str_replace', 'sprintf'] as $functionName) {
            $refFunction    = new ReflectionFunction($functionName);
            $nativeFunction = new \ReflectionFunction($functionName);
            foreach ($nativeFunction->getParameters() as $nativeParameter) {
                $entry = $refFunction->getArgumentInfo($nativeParameter->getPosition());
                $this->assertSame($nativeParameter->getName(), $entry->getName());
                $this->assertSame($nativeParameter->isPassedByReference(), $entry->isByReference());
                $this->assertSame($nativeParameter->isVariadic(), $entry->isVariadic());
            }
        }
    }

    public function testInternalFunctionReturnTypeEntry(): void
    {
        $refFunction = new ReflectionFunction('strlen');

        $returnEntry = $refFunction->getArgumentInfo(ArgumentEntry::RETURN_ENTRY_INDEX);
        $this->assertTrue($returnEntry->isReturnEntry());
        // The name field of an internal return entry holds the required-argument count
        // and must never be dereferenced - it is always reported as null
        $this->assertNull($returnEntry->getName());
        $this->assertTrue($returnEntry->mayBeOfType(ReflectionValue::IS_LONG));
    }

    public function testUserFunctionVariableNames(): void
    {
        $refFunction = new ReflectionFunction(__NAMESPACE__ . '\localVariablesFunction');

        // Slot numbers are the ExecutionData::getCallVariableByNumber() numbering:
        // arguments first (declaration order), then locals by first appearance
        $this->assertSame(['first', 'second', 'sum', 'conditional'], $refFunction->getVariableNames());
    }

    public function testInternalFunctionHasNoVariableNames(): void
    {
        $refFunction = new ReflectionFunction('strlen');

        // Internal functions carry no op_array, hence no compiled variables: this is
        // an empty set by definition, not a misuse - no exception is thrown
        $this->assertSame([], $refFunction->getVariableNames());
    }

    public function testStaticVariablesOfNamedFunction(): void
    {
        $refFunction = new ReflectionFunction(__NAMESPACE__ . '\staticVariablesFunction');

        // Whether or not the live table was already materialized by an earlier run, the
        // engine-level view must track the exact number of performed calls
        $table = $refFunction->getStaticVariables();
        $this->assertNotNull($table);
        $entry = $table->find('invocations');
        $this->assertNotNull($entry);
        $before = null;
        $entry->getNativeValue($before);
        $this->assertIsInt($before);

        $expected = staticVariablesFunction();
        staticVariablesFunction();

        // After the first call the engine materializes the live table
        // (static_variables_ptr); the reader must follow it instead of the defaults
        $table = $refFunction->getStaticVariables();
        $this->assertNotNull($table);
        $entry = $table->find('invocations');
        $this->assertNotNull($entry);
        $after = null;
        $entry->getNativeValue($after);
        $this->assertSame($expected + 1, $after);
        $this->assertSame($before + 2, $after);
    }

    public function testStaticVariablesOfClosure(): void
    {
        $closure = function (): int {
            /** @var int $counter */
            static $counter = 0;

            return ++$counter;
        };
        $closure();
        $closure();
        $closure();

        // Closures materialize their own static variables table at creation time
        $closureEntry = new ClosureEntry($closure);
        $refFunction  = ReflectionFunction::fromCData($closureEntry->getRawFunction());
        $table        = $refFunction->getStaticVariables();
        $this->assertNotNull($table);
        $entry = $table->find('counter');
        $this->assertNotNull($entry);
        $counterValue = null;
        $entry->getNativeValue($counterValue);
        $this->assertSame(3, $counterValue);
    }

    public function testStaticVariablesAreNullWithoutDeclaration(): void
    {
        $refFunction = new ReflectionFunction(__NAMESPACE__ . '\untypedInfoFunction');
        $this->assertNull($refFunction->getStaticVariables());
    }

    public function testStaticVariablesRequireUserFunction(): void
    {
        $refFunction = new ReflectionFunction('strlen');
        $this->expectException(\LogicException::class);
        $refFunction->getStaticVariables();
    }

    public function testTryCatchElementsForTryCatchFinally(): void
    {
        $refFunction = new ReflectionFunction(__NAMESPACE__ . '\tryCatchFinallyFunction');

        $elements = $refFunction->getTryCatchElements();
        $this->assertCount(1, $elements);
        $element = $elements[0];
        $this->assertTrue($element->hasCatch());
        $this->assertTrue($element->hasFinally());
        $this->assertGreaterThan($element->getTryOp(), $element->getCatchOp());
        $this->assertGreaterThan($element->getCatchOp(), $element->getFinallyOp());
        $this->assertGreaterThanOrEqual($element->getFinallyOp(), $element->getFinallyEnd());
    }

    public function testTryCatchElementsForTryFinally(): void
    {
        $refFunction = new ReflectionFunction(__NAMESPACE__ . '\tryFinallyFunction');

        $elements = $refFunction->getTryCatchElements();
        $this->assertCount(1, $elements);
        $this->assertFalse($elements[0]->hasCatch());
        $this->assertSame(0, $elements[0]->getCatchOp());
        $this->assertTrue($elements[0]->hasFinally());
    }

    public function testTryCatchElementsAreEmptyWithoutExceptionRegions(): void
    {
        $refFunction = new ReflectionFunction(__NAMESPACE__ . '\untypedInfoFunction');
        $this->assertSame([], $refFunction->getTryCatchElements());
    }

    public function testTryCatchElementsRequireUserFunction(): void
    {
        $refFunction = new ReflectionFunction('strlen');
        $this->expectException(\LogicException::class);
        $refFunction->getTryCatchElements();
    }

    public function testLiveRangesOfLoopAreNotEmpty(): void
    {
        $refFunction = new ReflectionFunction(__NAMESPACE__ . '\liveRangeFunction');

        $liveRanges = $refFunction->getLiveRanges();
        $this->assertNotEmpty($liveRanges);
        $loopRanges = 0;
        foreach ($liveRanges as $liveRange) {
            $this->assertInstanceOf(LiveRange::class, $liveRange);
            $this->assertGreaterThan($liveRange->getStart(), $liveRange->getEnd());
            $this->assertGreaterThanOrEqual(0, $liveRange->getVariableOffset());
            if ($liveRange->getKind() === LiveRange::KIND_LOOP) {
                $loopRanges++;
            }
        }
        $this->assertGreaterThan(0, $loopRanges, 'A foreach loop should produce a KIND_LOOP live range');
    }

    public function testLiveRangesAreEmptyForPlainFunction(): void
    {
        $refFunction = new ReflectionFunction(__NAMESPACE__ . '\untypedInfoFunction');
        $this->assertSame([], $refFunction->getLiveRanges());
    }

    public function testFileNameAndFunctionNameOfNamedFunction(): void
    {
        $refFunction = new ReflectionFunction(__NAMESPACE__ . '\argumentInfoFunction');

        $this->assertSame(__FILE__, $refFunction->getFileName());
        $this->assertSame(__NAMESPACE__ . '\argumentInfoFunction', $refFunction->getFunctionName());
    }

    public function testFileNameAndFunctionNameOfMethod(): void
    {
        $refMethod = new ReflectionMethod(self::class, __FUNCTION__);

        $this->assertSame(__FILE__, $refMethod->getFileName());
        // The engine stores the plain method name; the class is carried by the scope
        $this->assertSame(__FUNCTION__, $refMethod->getFunctionName());
    }

    public function testFileNameAndFunctionNameOfClosure(): void
    {
        $closure = static function (): int {
            return 42;
        };

        // The key case: the wrapper is built from the raw zend_function pointer without
        // ever constructing native reflection (which can throw for closure-backed
        // entries inside FFI callbacks), yet both accessors must serve real values
        $closureEntry = new ClosureEntry($closure);
        $refFunction  = ReflectionFunction::fromCData($closureEntry->getRawFunction());

        $this->assertSame(__FILE__, $refFunction->getFileName());
        $functionName = $refFunction->getFunctionName();
        $this->assertNotNull($functionName);
        $this->assertStringStartsWith('{closure', $functionName);
    }

    public function testFileNameAndFunctionNameOfInternalFunction(): void
    {
        $refFunction = new ReflectionFunction('strlen');

        // Internal functions carry no op_array, hence no declaring file: this is a real
        // null by definition, not a misuse - no exception is thrown
        $this->assertNull($refFunction->getFileName());
        // The name is still served through the common struct
        $this->assertSame('strlen', $refFunction->getFunctionName());
    }
}
