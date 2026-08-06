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

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZEngine\Core;
use ZEngine\Stub\TestClass;
use ZEngine\Stub\TestFirstConflictTrait;
use ZEngine\Stub\TestSecondConflictTrait;
use ZEngine\Stub\TestTraitConfiguredClass;

class ReflectionClassTraitConfigurationTest extends TestCase
{
    public function testGetTraitAliasesReadsEngineStructures(): void
    {
        $refClass = new ReflectionClass(TestTraitConfiguredClass::class);

        $aliases = $refClass->getTraitAliases();
        $this->assertSame(
            [
                'secondConflicting' => [
                    'trait'  => TestSecondConflictTrait::class,
                    'method' => 'conflicting',
                    'flags'  => 0,
                ],
                'politeGreet' => [
                    'trait'  => null,
                    'method' => 'greet',
                    'flags'  => Core::ZEND_ACC_PROTECTED,
                ],
            ],
            $aliases,
        );
    }

    public function testGetTraitPrecedencesReadsEngineStructures(): void
    {
        $refClass = new ReflectionClass(TestTraitConfiguredClass::class);

        $precedences = $refClass->getTraitPrecedences();
        $this->assertSame(
            [TestFirstConflictTrait::class . '::conflicting' => [TestSecondConflictTrait::class]],
            $precedences,
        );
    }

    public function testTraitConfigurationReadersAreEmptyWithoutAdaptations(): void
    {
        $refClass = new ReflectionClass(TestClass::class);
        $this->assertSame([], $refClass->getTraitAliases());
        $this->assertSame([], $refClass->getTraitPrecedences());
    }

    public function testAddTraitAliasRejectsUnknownFlags(): void
    {
        $refClass = new ReflectionClass(TestClass::class);
        $this->expectException(\ReflectionException::class);
        $this->expectExceptionMessage('public/protected/private/final');
        $refClass->addTraitAlias('someMethod', 'aliasedName', Core::ZEND_ACC_STATIC);
    }

    public function testAddTraitPrecedenceRequiresQualifiedReference(): void
    {
        $refClass = new ReflectionClass(TestClass::class);
        $this->expectException(\ReflectionException::class);
        $this->expectExceptionMessage('qualified');
        $refClass->addTraitPrecedence('unqualifiedMethod', 'SomeTrait');
    }

    public function testAddTraitPrecedenceRequiresExcludedTraits(): void
    {
        $refClass = new ReflectionClass(TestClass::class);
        $this->expectException(\ReflectionException::class);
        $this->expectExceptionMessage('At least one trait name');
        $refClass->addTraitPrecedence('SomeTrait::method');
    }

    /**
     * Installs trait aliases on a class entry while the engine is linking it: the class
     * declaration triggers the autoload of its trait, and inside the autoloader the
     * (still unlinked) class entry is already published in the class table
     */
    #[Group('internal')]
    public function testAddTraitAliasAppliesOnFutureLinking(): void
    {
        $unlinkedAliases = null;
        $loader          = static function (string $className) use (&$unlinkedAliases): void {
            if ($className !== 'TraitAliasProbeTrait') {
                return;
            }
            $refClass = new ReflectionClass('TraitAliasProbeUser');
            $refClass->addTraitAlias('TraitAliasProbeTrait::sourceMethod', 'aliasedMethod');
            // Unqualified reference plus a visibility change
            $refClass->addTraitAlias('sourceMethod', 'protectedAlias', Core::ZEND_ACC_PROTECTED);
            // Removed before linking: must never reach the method table
            $refClass->addTraitAlias('TraitAliasProbeTrait::sourceMethod', 'droppedAlias');
            $refClass->removeTraitAlias('droppedAlias');
            $unlinkedAliases = $refClass->getTraitAliases();
            eval('trait TraitAliasProbeTrait { public function sourceMethod(): string { return "from-trait"; } }');
        };
        spl_autoload_register($loader);
        try {
            eval('class TraitAliasProbeUser { use TraitAliasProbeTrait; }');
        } finally {
            spl_autoload_unregister($loader);
        }

        // The engine-level reader already saw the configuration on the unlinked class
        $this->assertSame(
            [
                'aliasedMethod' => [
                    'trait'  => 'TraitAliasProbeTrait',
                    'method' => 'sourceMethod',
                    'flags'  => 0,
                ],
                'protectedAlias' => [
                    'trait'  => null,
                    'method' => 'sourceMethod',
                    'flags'  => Core::ZEND_ACC_PROTECTED,
                ],
            ],
            $unlinkedAliases,
        );

        // ...and the linked class resolved the aliased methods for real (the class only
        // exists at runtime, so instantiation and calls go through dynamic names)
        $probeClass = new ReflectionClass('TraitAliasProbeUser');
        $instance   = $probeClass->newInstance();
        $this->assertTrue(method_exists($instance, 'sourceMethod'));
        $this->assertSame('from-trait', (new \ReflectionMethod($instance, 'aliasedMethod'))->invoke($instance));
        $protectedAlias = new \ReflectionMethod($instance, 'protectedAlias');
        $this->assertTrue($protectedAlias->isProtected());
        $this->assertFalse(method_exists($instance, 'droppedAlias'));
    }

    /**
     * Same pre-linking flow for a precedence: without the installed insteadof rule the
     * class below would fail to link with a method collision fatal error
     */
    #[Group('internal')]
    public function testAddTraitPrecedenceAppliesOnFutureLinking(): void
    {
        $unlinkedPrecedences = null;
        $loader              = static function (string $className) use (&$unlinkedPrecedences): void {
            if ($className !== 'TraitPrecedenceProbeFirst') {
                return;
            }
            $refClass = new ReflectionClass('TraitPrecedenceProbeUser');
            $refClass->addTraitPrecedence('TraitPrecedenceProbeFirst::resolve', 'TraitPrecedenceProbeSecond');
            $unlinkedPrecedences = $refClass->getTraitPrecedences();
            eval('trait TraitPrecedenceProbeFirst { public function resolve(): string { return "first"; } }');
            eval('trait TraitPrecedenceProbeSecond { public function resolve(): string { return "second"; } }');
        };
        spl_autoload_register($loader);
        try {
            eval('class TraitPrecedenceProbeUser { use TraitPrecedenceProbeFirst, TraitPrecedenceProbeSecond; }');
        } finally {
            spl_autoload_unregister($loader);
        }

        $this->assertSame(
            ['TraitPrecedenceProbeFirst::resolve' => ['TraitPrecedenceProbeSecond']],
            $unlinkedPrecedences,
        );

        // The insteadof rule picked the body of the first trait (dynamic access again:
        // the class only exists at runtime)
        $probeClass = new ReflectionClass('TraitPrecedenceProbeUser');
        $instance   = $probeClass->newInstance();
        $this->assertSame('first', (new \ReflectionMethod($instance, 'resolve'))->invoke($instance));
    }

    #[Group('internal')]
    public function testRemoveTraitAliasReleasesCompileTimeEntries(): void
    {
        // Bounded residual: replacing the engine-original (compiler-emalloc'd) alias list
        // leaves that list allocated until the request ends (see docs/long-running.md);
        // the debug-build shutdown report would flag it although nothing is wrong
        @ini_set('report_memleaks', '0'); // deprecated in PHP 8.5, still the only leak-report switch
        $refClass = new ReflectionClass(TestTraitConfiguredClass::class);

        $refClass->removeTraitAlias('secondConflicting');
        $aliases = $refClass->getTraitAliases();
        $this->assertArrayNotHasKey('secondConflicting', $aliases);
        $this->assertArrayHasKey('politeGreet', $aliases);

        $refClass->removeTraitAlias('politeGreet');
        $this->assertSame([], $refClass->getTraitAliases());

        $this->expectException(\ReflectionException::class);
        $refClass->removeTraitAlias('politeGreet');
    }

    #[Group('internal')]
    public function testRemoveTraitPrecedenceReleasesCompileTimeEntries(): void
    {
        // Bounded residual: the replaced engine-original precedence list stays allocated
        // (see docs/long-running.md), which the debug-build shutdown report would flag
        @ini_set('report_memleaks', '0'); // deprecated in PHP 8.5, still the only leak-report switch
        $refClass = new ReflectionClass(TestTraitConfiguredClass::class);

        $refClass->removeTraitPrecedence(TestFirstConflictTrait::class . '::conflicting');
        $this->assertSame([], $refClass->getTraitPrecedences());

        $this->expectException(\ReflectionException::class);
        $refClass->removeTraitPrecedence(TestFirstConflictTrait::class . '::conflicting');
    }

    #[Group('internal')]
    public function testRuntimeAliasCanBeInspectedAndRemovedOnLinkedClass(): void
    {
        $refClass = new ReflectionClass(TestClass::class);

        // Writes on a linked class only affect future linking: the reader must expose
        // them while the method table stays untouched
        $refClass->addTraitAlias('ZEngine\Stub\TestTrait::foo', 'renamedFoo', Core::ZEND_ACC_FINAL);
        $this->assertSame(
            ['renamedFoo' => ['trait' => 'ZEngine\Stub\TestTrait', 'method' => 'foo', 'flags' => Core::ZEND_ACC_FINAL]],
            $refClass->getTraitAliases(),
        );
        $this->assertFalse(method_exists(TestClass::class, 'renamedFoo'));

        $refClass->removeTraitAlias('renamedFoo');
        $this->assertSame([], $refClass->getTraitAliases());
    }
}
