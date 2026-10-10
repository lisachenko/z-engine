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
use ZEngine\Stub\Alias\SurveyBase;
use ZEngine\Stub\Alias\SurveyChild;
use ZEngine\Stub\Alias\SurveyGrandChild;
use ZEngine\Stub\Alias\SurveyPlainChild;
use ZEngine\Stub\Alias\SurveyUnrelated;

/**
 * The one-walk survey a body swap takes of the tables publishing its entry: the shares the
 * engine's inheritance took on the structure, the subclasses holding an engine clone of it,
 * and the wrapper-light class-table walk behind it (HashTable::pointerValueAddresses(),
 * ReflectionClass::getLinkedSubclasses())
 *
 * The Survey* stubs are never redefined: SurveyBase declares m(), SurveyPlainChild inherits
 * its pointer, SurveyChild implements an interface declaring m() and so holds a clone of the
 * entry, SurveyGrandChild inherits that clone's pointer, and SurveyUnrelated declares an m()
 * of its own.
 */
final class EntryPublicationsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Link every stub before the surveys (autoloading inside a survey is not the subject)
        class_exists(SurveyPlainChild::class);
        class_exists(SurveyGrandChild::class);
        class_exists(SurveyUnrelated::class);
        require_once __DIR__ . '/../Stub/Alias/LiveStatics-functions.php';
    }

    public function testASurveyCountsThePointerSharesAndFindsTheClones(): void
    {
        $entry  = (new ReflectionClass(SurveyBase::class))->getMethod('m');
        $survey = EntryPublications::survey($entry);

        self::assertSame(2, $survey->sharesOf($entry), 'the declaring class and the plain subclass publish the pointer');
        self::assertSame(
            [SurveyBase::class, SurveyPlainChild::class],
            self::names($survey->holdersOf($entry)),
        );

        $clones = $survey->clonesOf($entry);
        self::assertCount(1, $clones, 'the interface-implementing subclass holds the only clone');
        [$holder, $clone] = $clones[0];
        self::assertSame(SurveyChild::class, $holder->getName());
        self::assertNotSame($entry->getAddress(), $clone->getAddress(), 'a clone is a struct of its own');
        self::assertSame(SurveyBase::class, self::scopeName($clone), 'the clone carries the scope of the entry');

        // The clone is published by pointer into the tables below it, like any entry
        self::assertSame(2, $survey->sharesOf($clone));
        self::assertSame([SurveyChild::class, SurveyGrandChild::class], self::names($survey->holdersOf($clone)));
        self::assertSame([], $survey->clonesOf($clone), 'nothing below the clone holds a clone of it');
    }

    public function testAnUnrelatedMethodOfTheSameNameIsNeitherAHolderNorAClone(): void
    {
        $unrelated = (new ReflectionClass(SurveyUnrelated::class))->getMethod('m');
        $survey    = EntryPublications::survey($unrelated);

        self::assertSame(1, $survey->sharesOf($unrelated));
        self::assertSame([SurveyUnrelated::class], self::names($survey->holdersOf($unrelated)));
        self::assertSame([], $survey->clonesOf($unrelated));
    }

    public function testAGlobalFunctionIsPublishedInNoClassTable(): void
    {
        $entry  = new ReflectionFunction('zengine_live_statics_fn');
        $survey = EntryPublications::survey($entry);

        self::assertSame(1, $survey->sharesOf($entry), 'a plain function entry counts as one share');
        self::assertSame([], $survey->holdersOf($entry));
        self::assertSame([], $survey->clonesOf($entry));
    }

    public function testTheWrapperLightClassWalkListsEveryClassEntryOnce(): void
    {
        $expected = [];
        foreach (Core::$executor->classTable as $classValue) {
            try {
                $expected[] = Core::addressOf($classValue->getRawClass());
            } catch (\UnexpectedValueException $e) {
                // An alias bucket resolves to an entry listed under its own name
            }
        }
        $addresses = Core::$executor->classTable->pointerValueAddresses();

        self::assertNotEmpty($addresses);
        self::assertSame($expected, $addresses, 'the integer view reads exactly the pointer values the iterator yields');
        self::assertSame($addresses, array_values(array_unique($addresses)), 'a class alias adds no second entry');
    }

    public function testTheLinkedSubclassesAreFoundWithoutAWrapperPerClass(): void
    {
        $base = new ReflectionClass(SurveyBase::class);
        self::assertSame(
            [SurveyChild::class, SurveyGrandChild::class, SurveyPlainChild::class],
            self::names($base->getLinkedSubclasses()),
        );
        self::assertSame([SurveyGrandChild::class], self::names((new ReflectionClass(SurveyChild::class))->getLinkedSubclasses()));
        self::assertSame([], (new ReflectionClass(SurveyGrandChild::class))->getLinkedSubclasses());
        self::assertSame([], (new ReflectionClass(SurveyUnrelated::class))->getLinkedSubclasses());
    }

    /**
     * @param list<ReflectionClass> $classes
     *
     * @return list<string>
     */
    private static function names(array $classes): array
    {
        $names = array_map(static fn(ReflectionClass $class): string => $class->getName(), $classes);
        sort($names);

        return $names;
    }

    private static function scopeName(ReflectionMethod $method): string
    {
        $scope = $method->getCommonPointer()->scope;
        self::assertNotNull($scope);

        return ReflectionClass::fromCData($scope)->getName();
    }
}
