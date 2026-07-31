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

namespace ZEngine\System;

use FFI\CData;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ZEngine\Core;
use ZEngine\System\Hook\AstProcessHook;

class CompilerTest extends TestCase
{
    public function testGetCurrentLineNumberOutsideCompilation(): void
    {
        $lineNumber = Core::$compiler->getCurrentLineNumber();

        // Outside of compilation CG(zend_lineno) just holds the last recorded value
        $this->assertGreaterThanOrEqual(0, $lineNumber);
    }

    public function testGetAutoGlobalsContainsKnownAutoGlobals(): void
    {
        $autoGlobals = Core::$compiler->getAutoGlobals();

        // Values in this table are raw zend_auto_global* pointers, only keys are inspected
        $this->assertNotNull($autoGlobals->find('_SERVER'), 'CG(auto_globals) must contain _SERVER');
        $this->assertNotNull($autoGlobals->find('GLOBALS'), 'CG(auto_globals) must contain GLOBALS');
    }

    public function testExtraFnFlagsRoundTrip(): void
    {
        $originalFlags = Core::$compiler->getExtraFnFlags();

        $newFlags = $originalFlags | Core::ZEND_ACC_GENERATOR;
        try {
            $previousFlags = Core::$compiler->setExtraFnFlags($newFlags);
            $this->assertSame($originalFlags, $previousFlags);
            $this->assertSame($newFlags, Core::$compiler->getExtraFnFlags());

            $this->assertSame($newFlags, Core::$compiler->setExtraFnFlags($originalFlags));
        } finally {
            Core::$compiler->setExtraFnFlags($originalFlags);
        }
        $this->assertSame($originalFlags, Core::$compiler->getExtraFnFlags());
    }

    public function testGetActiveClassEntryIsNullOutsideCompilation(): void
    {
        $this->assertNull(Core::$compiler->getActiveClassEntry());
    }

    public function testGetActiveOpArrayIsNullOutsideCompilation(): void
    {
        $this->assertNull(Core::$compiler->getActiveOpArray());
    }

    /**
     * CG(active_op_array) must be observable while the engine compiles code: the
     * `zend_ast_process` callback runs after the engine has allocated the op_array
     * of the compile unit, but BEFORE zend_compile_top_stmt() walks the AST - so
     * CG(active_class_entry) is intentionally still NULL at that point (it is only
     * set while the class statement itself is being compiled).
     */
    #[Group('internal')]
    #[RunInSeparateProcess]
    public function testCompilationStateIsObservableFromAstProcessHook(): void
    {
        $hookFired     = false;
        $inCompilation = null;
        $hasOpArray    = null;
        $lineNumber    = null;
        $activeClass   = null;

        $hook = Core::setASTProcessHandler(
            function (AstProcessHook $hook) use (&$hookFired, &$inCompilation, &$hasOpArray, &$lineNumber, &$activeClass): void {
                $compiler = Core::$compiler;
                // Only scalar observations escape the hook: the op_array pointer is
                // borrowed and valid only while this compilation is running
                $hookFired     = true;
                $inCompilation = $compiler->isInCompilation();
                $hasOpArray    = $compiler->getActiveOpArray() instanceof CData;
                $lineNumber    = $compiler->getCurrentLineNumber();
                $activeClass   = $compiler->getActiveClassEntry();
            },
        );

        try {
            eval('class CompilerTestAstProbe { public function hello(): string { return "hi"; } }');
        } finally {
            $hook->uninstall();
        }

        $this->assertTrue(class_exists('CompilerTestAstProbe', false));
        $this->assertTrue($hookFired, 'The zend_ast_process hook must fire during eval()');
        $this->assertTrue($inCompilation);
        $this->assertTrue($hasOpArray, 'CG(active_op_array) must be set during compilation');
        $this->assertNotNull($lineNumber);
        $this->assertGreaterThanOrEqual(1, $lineNumber);
        // zend_ast_process fires before class statements are compiled, see the docblock
        $this->assertNull($activeClass);

        // Compilation is over: the engine has restored both pointers
        $this->assertNull(Core::$compiler->getActiveOpArray());
        $this->assertNull(Core::$compiler->getActiveClassEntry());
    }

    /**
     * The non-null branch of getActiveClassEntry(): point CG(active_class_entry) at a
     * real engine-owned zend_class_entry and read it back through the accessor. Mutates
     * raw engine globals, hence the internal group + process isolation.
     */
    #[Group('internal')]
    #[RunInSeparateProcess]
    public function testGetActiveClassEntryWrapsEngineClassEntry(): void
    {
        $classEntryValue = Core::$executor->classTable->find(strtolower(\ArrayObject::class));
        $this->assertNotNull($classEntryValue);
        $classEntry = $classEntryValue->getRawClass();

        $pointerProperty = new \ReflectionProperty(Compiler::class, 'pointer');
        $compilerGlobals = $pointerProperty->getValue(Core::$compiler);
        assert($compilerGlobals instanceof CData);

        // While CG(active_class_entry) is set the engine believes it is compiling that
        // class, so NOTHING may be compiled (no autoloading, hence no PHPUnit assertions)
        // until the pointer is restored - only capture values inside this window
        try {
            $compilerGlobals->active_class_entry = $classEntry;

            $activeClass     = Core::$compiler->getActiveClassEntry();
            $activeClassName = $activeClass?->getName();
        } finally {
            $compilerGlobals->active_class_entry = null;
        }

        $this->assertNotNull($activeClass);
        $this->assertSame(\ArrayObject::class, $activeClassName);
        $this->assertNull(Core::$compiler->getActiveClassEntry());
    }
}
