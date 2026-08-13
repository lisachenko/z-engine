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

namespace ZEngine\HotSwap;

use ZEngine\AbstractSyntaxTree\NodeInterface;
use ZEngine\AbstractSyntaxTree\NodeKind;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\StructArray;

/**
 * Entry point of the atomic runtime class hot-swap API
 *
 * prepare() takes freshly written source for an ALREADY LOADED class, validates it
 * (Compiler::parseString()), compiles it into a hidden donor class entry with the
 * engine's own compiler and computes a ClassDelta against the live class entry.
 * The live class is untouched until ClassDelta::apply() runs; apply() stages every
 * mutation and rolls all of them back if any step fails, so no half-swapped class
 * is ever observable. See docs/hot-swap.md for the full contract, the delta
 * support matrix and the memory-ownership rules.
 *
 * The donor compilation works by unpublishing the live class from the class table
 * for the duration of one eval() of the new source (the engine then accepts the
 * duplicate declaration), republishing the live entry and unpublishing the donor.
 * The source contract follows from that mechanism: it must declare the target
 * class - with the same fully-qualified name, the same parent and the same
 * interfaces - and nothing else that would collide with loaded code.
 */
final class HotSwap
{
    /**
     * This is an utility class, no instances needed
     */
    private function __construct() {}

    /**
     * Prepares an atomic delta between a loaded class and its freshly rewritten source
     *
     * INTERIM SECURITY NOTE: the donor class is currently compiled by eval()-ing the
     * source, which executes it at compile time. Replacing this with a pure
     * parse/AST/compile path that never runs the source body is tracked as issue #110
     * (Refs #110); until then callers must only pass trusted source.
     *
     * @param string $className  Name of the loaded class to hot-swap
     * @param string $sourceCode Full new source of the class declaration (with <?php-less
     *                           plain PHP statements, exactly what eval() accepts)
     *
     * @throws HotSwapException When the source does not parse, does not declare the class,
     *                          or declares an incompatible class shape (see docs/hot-swap.md)
     */
    public static function prepare(string $className, string $sourceCode): ClassDelta
    {
        $normalizedName = ltrim($className, '\\');
        $lowerName      = strtolower($normalizedName);

        $classTable      = Core::$executor->classTable;
        $classEntryValue = $classTable->find($lowerName);
        if ($classEntryValue === null) {
            throw HotSwapException::classNotLoaded($normalizedName);
        }
        $liveClass = ReflectionClass::fromCData($classEntryValue->getRawClass());
        if ($liveClass->isImmutable()) {
            // The delta is applied in place, which opcache shared memory never allows: the
            // class entry is copied out into a writable per-process copy first (the class
            // table bucket is repointed, the shared original stays untouched) and the whole
            // hot-swap then runs against that copy. See docs/hot-swap.md for the caveats.
            $liveClass->copyOutOfSharedMemory();
        }
        self::assertPlainLinkedUserClass($liveClass, $normalizedName);

        // Validation pass: the source must parse and declare the target class
        self::assertSourceDeclaresClass($normalizedName, $sourceCode);

        $donorClass = self::compileDonorClass($lowerName, $normalizedName, $liveClass, $sourceCode);

        try {
            self::assertCompatibleShape($liveClass, $donorClass, $normalizedName);

            return ClassDelta::fromDiff($normalizedName, $liveClass, $donorClass);
        } catch (\Throwable $error) {
            ClassDelta::destroyClassEntry($donorClass);
            throw $error;
        }
    }

    /**
     * Rejects target classes the delta engine has no defined semantics for
     */
    private static function assertPlainLinkedUserClass(ReflectionClass $liveClass, string $className): void
    {
        if (!$liveClass->isUserDefined()) {
            throw HotSwapException::internalClass($className);
        }
        $classFlags = $liveClass->getFlags();
        if (($classFlags & Core::ZEND_ACC_LINKED) === 0) {
            throw HotSwapException::notLinked($className);
        }
        $specialMask = Core::ZEND_ACC_INTERFACE | Core::ZEND_ACC_TRAIT | Core::ZEND_ACC_ENUM;
        if (($classFlags & $specialMask) !== 0) {
            throw HotSwapException::unsupportedKind($className);
        }
    }

    /**
     * Parses the source (engine scanner/parser) and checks it declares the target class
     */
    private static function assertSourceDeclaresClass(string $className, string $sourceCode): void
    {
        $shortName = ($slashPosition = strrpos($className, '\\')) === false
            ? $className
            : substr($className, $slashPosition + 1);
        try {
            $tree = Core::$compiler->parseString($sourceCode, "hot-swap of {$className}");
        } catch (\Throwable $error) {
            throw HotSwapException::sourceDoesNotParse($className, $error);
        }

        $declaredNames = [];
        self::collectClassDeclarationNames($tree, $declaredNames);
        // Keep the parsed tree (and its arena) alive until the walk is complete
        unset($tree);

        foreach ($declaredNames as $declaredName) {
            if (strcasecmp($declaredName, $shortName) === 0) {
                return;
            }
        }

        throw HotSwapException::sourceMissingClass(
            $shortName,
            $declaredNames === [] ? '(no class declaration)' : join(', ', $declaredNames),
        );
    }

    /**
     * Walks a parsed tree collecting the names of declared classes
     *
     * @param array<int, string> $names Collected declaration names (by reference)
     */
    private static function collectClassDeclarationNames(NodeInterface $node, array &$names): void
    {
        if ($node->getKind() === NodeKind::AST_CLASS) {
            /** @var \ZEngine\AbstractSyntaxTree\DeclarationNode $node */
            $names[] = $node->getName();

            return;
        }
        foreach ($node->getChildren() as $childNode) {
            if ($childNode instanceof NodeInterface) {
                self::collectClassDeclarationNames($childNode, $names);
            }
        }
    }

    /**
     * Compiles the new source into a donor class entry without disturbing the live class
     *
     * The live entry is unpublished from the class table around one eval() so the
     * engine accepts the redeclaration; the donor is unpublished right after and the
     * live entry is republished - both moves with the table destructor disabled, so
     * no class entry is ever destroyed by the shuffle.
     *
     * @return ReflectionClass Donor class (linked, refcount 1, unpublished)
     */
    private static function compileDonorClass(
        string $lowerName,
        string $className,
        ReflectionClass $liveClass,
        string $sourceCode,
    ): ReflectionClass {
        $classTable = Core::$executor->classTable;

        // INTERIM: the donor is compiled with eval() of the source. This executes the
        // source at compile time; replacing it with a pure parse/AST/compile path that
        // never runs the body is tracked as issue #110 (Refs #110). See HotSwap::prepare().
        self::unpublishClassEntry($lowerName);
        $donorClass   = null;
        $compileError = null;
        try {
            try {
                eval($sourceCode);
            } catch (\Throwable $error) {
                $compileError = $error;
            }
            // Even a failed eval may have completed the class declaration before
            // throwing - always check, so the live entry can be republished safely
            $donorEntryValue = $classTable->find($lowerName);
            if ($donorEntryValue !== null) {
                $donorClass = ReflectionClass::fromCData($donorEntryValue->getRawClass());
            }
        } finally {
            if ($donorClass !== null) {
                self::unpublishClassEntry($lowerName);
            }
            self::publishClassEntry($lowerName, $liveClass);
        }

        if ($compileError !== null) {
            if ($donorClass !== null) {
                ClassDelta::destroyClassEntry($donorClass);
            }
            throw HotSwapException::sourceDidNotCompile($className, $compileError);
        }
        if ($donorClass === null) {
            throw HotSwapException::classNotDeclared($className);
        }

        return $donorClass;
    }

    /**
     * Removes a class-table bucket without destroying the class entry it points to
     */
    private static function unpublishClassEntry(string $lowerName): void
    {
        // The table destructor is disabled around the delete so the bucket removal
        // releases nothing - the class entry survives and is republished afterwards
        Core::$executor->classTable->deleteWithoutDestructor($lowerName);
    }

    /**
     * Publishes a class entry pointer into the class table under the given key
     */
    private static function publishClassEntry(string $lowerName, ReflectionClass $reflectionClass): void
    {
        $rawClass   = (new StructArray($reflectionClass->getRawValue(), 1))[0];
        $valueEntry = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $rawClass);
        Core::$executor->classTable->add($lowerName, $valueEntry);
        $valueEntry->release();
    }

    /**
     * Verifies the donor keeps every class invariant the delta engine relies on
     *
     * The hierarchy (parent, interfaces) and the property surface (names, flags,
     * slot offsets, type masks) must be identical: property layout changes would
     * corrupt live instances, and hierarchy changes would invalidate variance and
     * every inherited structure. See docs/hot-swap.md.
     */
    private static function assertCompatibleShape(
        ReflectionClass $liveClass,
        ReflectionClass $donorClass,
        string $className,
    ): void {
        // Parent must be the same linked class entry
        if ($liveClass->getParentClass()?->getAddress() !== $donorClass->getParentClass()?->getAddress()) {
            throw HotSwapException::parentChanged($className);
        }

        // Interface sets must match (both classes are linked, so the resolved list is present)
        if (self::interfaceAddresses($liveClass) !== self::interfaceAddresses($donorClass)) {
            throw HotSwapException::interfacesChanged($className);
        }

        // The own property surface must be identical: layout changes cannot be applied
        // to a class with live instances
        if (self::ownPropertySurface($liveClass) !== self::ownPropertySurface($donorClass)) {
            throw HotSwapException::propertySurfaceChanged($className);
        }
    }

    /**
     * Returns the sorted numeric addresses of the class's resolved interface entries
     *
     * Reads through the native getInterfaces() override; each interface resolves to its
     * globally registered class entry, so live and donor produce comparable addresses.
     *
     * @return list<int>
     */
    private static function interfaceAddresses(ReflectionClass $reflectionClass): array
    {
        $addresses = [];
        foreach ($reflectionClass->getInterfaces() as $interface) {
            $addresses[] = $interface->getAddress();
        }
        sort($addresses);

        return $addresses;
    }

    /**
     * Returns the comparable declaration surface of every property the class declares
     *
     * @return array<string, array{int, int, int}> Property name => [flags, offset, type mask]
     */
    private static function ownPropertySurface(ReflectionClass $reflectionClass): array
    {
        $surface = [];
        foreach ($reflectionClass->getDeclaredProperties() as $propertyName => $property) {
            $surface[$propertyName] = $property->getSurface();
        }
        ksort($surface);

        return $surface;
    }
}
