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

use FFI\CData;
use ZEngine\AbstractSyntaxTree\NodeInterface;
use ZEngine\AbstractSyntaxTree\NodeKind;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\System\SharedMemory;
use ZEngine\Type\HashTable;

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
            throw new HotSwapException("Class {$normalizedName} is not loaded, nothing to hot-swap");
        }
        $classEntry = $classEntryValue->getRawClass();
        SharedMemory::assertMutableClassEntry($classEntry, 'hot-swap');
        self::assertPlainLinkedUserClass($classEntry, $normalizedName);

        // Validation pass: the source must parse and declare the target class
        self::assertSourceDeclaresClass($normalizedName, $sourceCode);

        $donorEntry = self::compileDonorClass($lowerName, $normalizedName, $classEntry, $sourceCode);

        try {
            self::assertCompatibleShape($classEntry, $donorEntry, $normalizedName);

            return ClassDelta::fromDiff($normalizedName, $classEntry, $donorEntry);
        } catch (\Throwable $error) {
            ClassDelta::destroyClassEntry($donorEntry);
            throw $error;
        }
    }

    /**
     * Rejects target classes the delta engine has no defined semantics for
     */
    private static function assertPlainLinkedUserClass(CData $classEntry, string $className): void
    {
        $classType = $classEntry->type;
        assert(is_string($classType));
        $isUserClass = ord($classType) === Core::ZEND_USER_CLASS;
        $classFlags  = $classEntry->ce_flags;
        assert(is_int($classFlags));
        if (!$isUserClass) {
            throw new HotSwapException("Cannot hot-swap internal class {$className}");
        }
        if (($classFlags & Core::ZEND_ACC_LINKED) === 0) {
            throw new HotSwapException("Cannot hot-swap class {$className}: it is not linked yet");
        }
        $specialMask = Core::ZEND_ACC_INTERFACE | Core::ZEND_ACC_TRAIT | Core::ZEND_ACC_ENUM;
        if (($classFlags & $specialMask) !== 0) {
            throw new HotSwapException(
                "Cannot hot-swap {$className}: interfaces, traits and enums are not supported",
            );
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
            throw new HotSwapException(
                "Hot-swap source for {$className} does not parse: {$error->getMessage()}",
                0,
                $error,
            );
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

        throw new HotSwapException(
            "Hot-swap source must declare class {$shortName}, found: "
            . ($declaredNames === [] ? '(no class declaration)' : join(', ', $declaredNames)),
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
     * @return CData Donor zend_class_entry pointer (linked, refcount 1, unpublished)
     */
    private static function compileDonorClass(
        string $lowerName,
        string $className,
        CData $classEntry,
        string $sourceCode,
    ): CData {
        $classTable = Core::$executor->classTable;

        self::unpublishClassEntry($lowerName);
        $donorEntry   = null;
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
                $donorEntry = $donorEntryValue->getRawClass();
            }
        } finally {
            if ($donorEntry !== null) {
                self::unpublishClassEntry($lowerName);
            }
            self::publishClassEntry($lowerName, $classEntry);
        }

        if ($compileError !== null) {
            if ($donorEntry !== null) {
                ClassDelta::destroyClassEntry($donorEntry);
            }
            throw new HotSwapException(
                "Hot-swap source for {$className} failed to compile: {$compileError->getMessage()}",
                0,
                $compileError,
            );
        }
        if ($donorEntry === null) {
            throw new HotSwapException(
                "Hot-swap source for {$className} compiled, but did not declare the class "
                . 'under its fully-qualified name (check the namespace statement)',
            );
        }

        return $donorEntry;
    }

    /**
     * Removes a class-table bucket without destroying the class entry it points to
     */
    private static function unpublishClassEntry(string $lowerName): void
    {
        $classTable = Core::$executor->classTable;
        $rawTable   = $classTable->getRawValue();

        $previousDestructor    = $rawTable->pDestructor;
        $rawTable->pDestructor = null;
        try {
            $classTable->delete($lowerName);
        } finally {
            $rawTable->pDestructor = $previousDestructor;
        }
    }

    /**
     * Publishes a class entry pointer into the class table under the given key
     */
    private static function publishClassEntry(string $lowerName, CData $classEntry): void
    {
        $rawClass = Core::cast('zend_class_entry *', $classEntry)[0];
        assert($rawClass instanceof CData);
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
    private static function assertCompatibleShape(CData $classEntry, CData $donorEntry, string $className): void
    {
        // Parent must be the same linked class entry
        $originalParent = $classEntry->parent;
        $donorParent    = $donorEntry->parent;
        assert($originalParent === null || $originalParent instanceof CData);
        assert($donorParent === null || $donorParent instanceof CData);
        $sameParent = ($originalParent === null && $donorParent === null)
            || ($originalParent !== null && $donorParent !== null
                                         && Core::addressOf($originalParent) === Core::addressOf($donorParent));
        if (!$sameParent) {
            throw new HotSwapException(
                "Hot-swap source for {$className} changes the parent class - hierarchy changes are not supported",
            );
        }

        // Interface sets must match (both classes are linked, so the resolved list is present)
        $originalInterfaces = self::interfaceAddressSet($classEntry);
        $donorInterfaces    = self::interfaceAddressSet($donorEntry);
        if ($originalInterfaces !== $donorInterfaces) {
            throw new HotSwapException(
                "Hot-swap source for {$className} changes the implemented interfaces - "
                . 'hierarchy changes are not supported',
            );
        }

        // The own property surface must be identical: layout changes cannot be applied
        // to a class with live instances
        $originalProperties = self::ownPropertySurface($classEntry);
        $donorProperties    = self::ownPropertySurface($donorEntry);
        if ($originalProperties !== $donorProperties) {
            throw new HotSwapException(
                "Hot-swap source for {$className} changes the property surface "
                . '(added/removed/reordered/retyped properties) - only default value changes are supported',
            );
        }
    }

    /**
     * Returns the sorted list of implemented interface entry addresses
     *
     * @return list<int>
     */
    private static function interfaceAddressSet(CData $classEntry): array
    {
        $addresses       = [];
        $totalInterfaces = $classEntry->num_interfaces;
        assert(is_int($totalInterfaces));
        $interfaceList = $classEntry->interfaces;
        for ($index = 0; $index < $totalInterfaces; $index++) {
            assert($interfaceList instanceof CData);
            $interfaceEntry = $interfaceList[$index];
            assert($interfaceEntry instanceof CData);
            $addresses[] = Core::addressOf($interfaceEntry);
        }
        sort($addresses);

        return $addresses;
    }

    /**
     * Returns the comparable shape of every property the class declares itself
     *
     * @return array<string, array{int, int, int}> Property name => [flags, offset, type mask]
     */
    private static function ownPropertySurface(CData $classEntry): array
    {
        $surface       = [];
        $classAddress  = Core::addressOf($classEntry);
        $rawProperties = $classEntry->properties_info;
        assert($rawProperties instanceof CData);
        $propertiesInfo = new HashTable(Core::addr($rawProperties));
        foreach ($propertiesInfo as $propertyName => $propertyValue) {
            assert($propertyValue instanceof ReflectionValue);
            $rawInfo        = Core::cast('zend_property_info *', $propertyValue->getRawPointer());
            $declaringClass = $rawInfo->ce;
            assert(is_string($propertyName) && $declaringClass instanceof CData);
            if (Core::addressOf($declaringClass) !== $classAddress) {
                continue;
            }
            $flags    = $rawInfo->flags;
            $offset   = $rawInfo->offset;
            $typeInfo = $rawInfo->type;
            assert(is_int($flags) && is_int($offset) && $typeInfo instanceof CData);
            $typeMask = $typeInfo->type_mask;
            assert(is_int($typeMask));
            $surface[$propertyName] = [$flags, $offset, $typeMask];
        }
        ksort($surface);

        return $surface;
    }
}
