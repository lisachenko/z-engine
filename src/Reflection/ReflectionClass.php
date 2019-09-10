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

use FFI;
use FFI\CData;
use ReflectionClass as NativeReflectionClass;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\HashTable;
use ZEngine\Type\StringEntry;

class ReflectionClass extends NativeReflectionClass
{
    /**
     * Stores the list of methods in the class
     *
     * @var HashTable|ReflectionValue[]
     */
    private HashTable $methodTable;

    /**
     * Stores the list of properties in the class
     *
     * @var HashTable|ReflectionValue[]
     */
    private HashTable $propertiesTable;

    /**
     * Stores the list of constants in the class
     *
     * @var HashTable|ReflectionValue[]
     */
    private HashTable $constantsTable;

    private CData $pointer;

    public function __construct($classNameOrObject)
    {
        try {
            parent::__construct($classNameOrObject);
        } catch (\ReflectionException $e) {
            // This can happen during the class-loading. But we still can work with it.
        }
        $className       = is_string($classNameOrObject) ? $classNameOrObject : get_class($classNameOrObject);
        $normalizedName  = strtolower($className);

        $classEntryValue = Core::$executor->classTable->find($normalizedName);
        if ($classEntryValue === null) {
            throw new \ReflectionException("Class {$className} should be in the engine.");
        }
        $classEntry = $classEntryValue->getRawValue()->ce;
        $this->initLowLevelStructures($classEntry);
    }

    /**
     * Creates a reflection from the zend_class_entry structure
     *
     * @param CData $classEntry Pointer to the structure
     *
     * @return ReflectionClass
     */
    public static function fromClassEntry(CData $classEntry): ReflectionClass
    {
        /** @var ReflectionClass $reflectionClass */
        $reflectionClass = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $classNameValue  = new StringEntry($classEntry->name);
        try {
            call_user_func([$reflectionClass, 'parent::__construct'], (string) $classNameValue);
        } catch (\ReflectionException $e) {
            // This can happen during the class-loading. But we still can work with it.
        }
        $reflectionClass->initLowLevelStructures($classEntry);

        return $reflectionClass;
    }

    /**
     * @inheritDoc
     */
    public function getInterfaceNames(): array
    {
        $interfaceNames = [];
        $isLinked       = (bool) ($this->pointer->ce_flags & Core::ZEND_ACC_LINKED);
        for ($index = 0; $index < $this->pointer->num_interfaces; $index++) {
            if ($isLinked) {
                $rawInterfaceName = $this->pointer->interfaces[$index]->name;
            } else {
                $rawInterfaceName = $this->pointer->interface_names[$index]->name;
            }
            $interfaceNameValue = new StringEntry($rawInterfaceName);
            $interfaceNames[]   = (string) $interfaceNameValue;
        }

        return $interfaceNames;
    }

    /**
     * Gets the interfaces
     *
     * @return ReflectionClass[] An associative array of interfaces, with keys as interface
     * names and the array values as <b>ReflectionClass</b> objects.
     */
    public function getInterfaces(): array
    {
        $interfaces = [];
        foreach ($this->getInterfaceNames() as $interfaceName) {
            $interfaces[$interfaceName] = new ReflectionClass($interfaceName);
        };

        return $interfaces;
    }

    /**
     * Adds interfaces to the current class
     *
     * @param string ...$interfaceNames Name of interfaces to add
     */
    public function addInterfaces(string ...$interfaceNames): void
    {
        $availableInterfaces = $this->getInterfaceNames();
        $interfacesToAdd     = array_diff($interfaceNames, $availableInterfaces);
        $numInterfacesToAdd  = count($interfacesToAdd);
        $totalInterfaces     = count($availableInterfaces);
        $numResultInterfaces = $totalInterfaces + $numInterfacesToAdd;

        // Allocate persistent non-owned memory, because this structure should be persistent in Opcache
        $memory    = Core::new("zend_class_entry *[$numResultInterfaces]", false, true);
        $itemsSize = FFI::sizeof(Core::type('zend_class_entry *'));
        FFI::memcpy($memory, $this->pointer->interfaces, $itemsSize * $totalInterfaces);
        for ($position = $totalInterfaces, $index = 0; $index < $numInterfacesToAdd; $position++, $index++) {
            $classValueEntry   = Core::$executor->classTable->find(strtolower($interfacesToAdd[$index]));
            $memory[$position] = $classValueEntry->getRawValue()->ce;
        }
        if($this->pointer->interfaces !== null) {
            FFI::memcpy($this->pointer->interfaces, $memory, FFI::sizeof($memory));
        } else {
            $this->pointer->interfaces = Core::cast('zend_class_entry **', FFI::addr($memory));
        };
        $this->pointer->num_interfaces = $numResultInterfaces;
    }

    /**
     * Removes interfaces from the current class
     *
     * @param string ...$interfaceNames Name of interfaces to remove
     */
    public function removeInterfaces(string ...$interfaceNames): void
    {
        $availableInterfaces = $this->getInterfaceNames();
        $indexesToRemove     = [];
        foreach ($interfaceNames as $interfaceToRemove) {
            $interfacePosition = array_search($interfaceToRemove, $availableInterfaces, true);
            if ($interfacePosition === false) {
                throw new \ReflectionException("Interface {$interfaceToRemove} doesn't belong to the class");
            }
            $indexesToRemove[$interfacePosition] = true;
        }
        $totalInterfaces     = count($availableInterfaces);
        $numResultInterfaces = $totalInterfaces - count($indexesToRemove);

        // If we remove all interfaces then just clear $this->pointer->interfaces field
        if ($numResultInterfaces === 0) {
            $this->pointer->interfaces = null;
        } else {
            // Allocate persistent non-owned memory, because this structure should be persistent in Opcache
            $memory = Core::new("zend_class_entry *[$numResultInterfaces]", false, true);
            for ($index = 0, $destIndex = 0; $index < $this->pointer->num_interfaces; $index++) {
                if (!isset($indexesToRemove[$index])) {
                    $memory[$destIndex++] = $this->pointer->interfaces[$index];
                }
            }
            FFI::memcpy($this->pointer->interfaces, $memory, FFI::sizeof($memory));
        }
        // Decrease the total number of interfaces in the class entry
        $this->pointer->num_interfaces = $numResultInterfaces;
    }

    /**
     * @inheritDoc
     */
    public function getMethod($name)
    {
        $functionEntry = $this->methodTable->find(strtolower($name));
        if ($functionEntry === null) {
            throw new \ReflectionException("Method {$name} does not exist");
        }

        return ReflectionMethod::fromFunctionEntry($functionEntry->getRawValue()->func);
    }

    /**
     * @inheritDoc
     */
    public function getMethods($filter = null)
    {
        $methods = [];
        foreach ($this->methodTable as $methodEntry) {
            $functionEntry = $methodEntry->getRawValue()->func;
            if (!isset($filter) || ($functionEntry->common->fn_flags & $filter)) {
                $methods[] = ReflectionMethod::fromFunctionEntry($functionEntry);
            }
        }

        return $methods;
    }

    /**
     * Removes given methods from the class
     *
     * @param string ...$methodNames Name of methods to remove
     */
    public function removeMethods(string ...$methodNames): void
    {
        foreach ($methodNames as $methodName) {
            $this->methodTable->delete(strtolower($methodName));
        }
    }

    /**
     * Gets the traits
     *
     * @return ReflectionClass[] An associative array of traits, with keys as trait
     * names and the array values as <b>ReflectionClass</b> objects.
     */
    public function getTraits(): array
    {
        $traits = [];
        foreach ($this->getTraitNames() as $traitName) {
            $traits[$traitName] = new ReflectionClass($traitName);
        };

        return $traits;
    }

    /**
     * Adds traits to the current class
     *
     * @param string ...$traitNames Name of traits to add
     */
    public function addTraits(string ...$traitNames): void
    {
        $availableTraits = $this->getTraitNames();
        $traitsToAdd     = array_diff($traitNames, $availableTraits);
        $numTraitsToAdd  = count($traitsToAdd);
        $totalTraits     = count($availableTraits);
        $numResultTraits = $totalTraits + $numTraitsToAdd;

        // Allocate persistent non-owned memory, because this structure should be persistent in Opcache
        $memory    = Core::new("zend_class_name [$numResultTraits]", false, true);
        $itemsSize = FFI::sizeof(Core::type('zend_class_name'));
        if ($this->pointer->num_traits > 0) {
            FFI::memcpy($memory, $this->pointer->trait_names, $itemsSize * $totalTraits);
        }
        for ($position = $totalTraits, $index = 0; $index < $numTraitsToAdd; $position++, $index++) {
            $name   = StringEntry::fromString($traitsToAdd[$index]);
            $lcName = StringEntry::fromString(strtolower($traitsToAdd[$index]));

            $memory[$position]->name    = $name->pointer;
            $memory[$position]->lc_name = $lcName->pointer;
        }
        if($this->pointer->trait_names !== null) {
            FFI::memcpy($this->pointer->trait_names, $memory, FFI::sizeof($memory));
        } else {
            $this->pointer->trait_names = Core::cast('zend_class_name *', FFI::addr($memory));
        };
        $this->pointer->num_traits = $numResultTraits;
    }

    /**
     * Removes traits from the current class
     *
     * @param string ...$traitNames Name of traits to remove
     */
    public function removeTraits(string ...$traitNames): void
    {
        $availableTraits = $this->getTraitNames();
        $indexesToRemove = [];
        foreach ($traitNames as $traitToRemove) {
            $traitPosition = array_search($traitToRemove, $availableTraits, true);
            if ($traitPosition === false) {
                throw new \ReflectionException("Trait {$traitToRemove} doesn't belong to the class");
            }
            $indexesToRemove[$traitPosition] = true;
        }
        $totalTraits     = count($availableTraits);
        $numResultTraits = $totalTraits - count($indexesToRemove);

        // If we remove all traits then just clear $this->pointer->trait_names field
        if ($numResultTraits === 0) {
            $this->pointer->trait_names = null;
        } else {
            // Allocate persistent non-owned memory, because this structure should be persistent in Opcache
            $memory = Core::new("zend_class_name[$numResultTraits]", false, true);
            for ($index = 0, $destIndex = 0; $index < $this->pointer->num_traits; $index++) {
                if (!isset($indexesToRemove[$index])) {
                    $memory[$destIndex++] = $this->pointer->trait_names[$index];
                }
            }
            FFI::memcpy($this->pointer->trait_names, $memory, FFI::sizeof($memory));
        }
        $this->pointer->num_traits = $numResultTraits;
    }

    /**
     * @inheritDoc
     */
    public function getParentClass(): ?ReflectionClass
    {
        if (!$this->hasParentClass()) {
            return null;
        }

        // For linked class we should look at parent name directly
        if ($this->pointer->ce_flags & Core::ZEND_ACC_LINKED) {
            $rawParentName = $this->pointer->parent->name;
        } else {
            $rawParentName = $this->pointer->parent_name;
        }

        $parentNameValue = new StringEntry($rawParentName);
        $classReflection = new ReflectionClass((string)$parentNameValue);

        return $classReflection;
    }

    /**
     * Removes the linked parent class from the existing class
     */
    public function removeParentClass(): void
    {
        if (!$this->hasParentClass()) {
            throw new \ReflectionException('Could not remove non-existent parent class');
        }
        try {
            $parentClass      = $this->getParentClass();
            $parentInterfaces = $parentClass->getInterfaceNames();
            if (count($parentInterfaces) > 0) {
                $this->removeInterfaces(...$parentInterfaces);
            }
        } catch (\ReflectionException $e) {
            // This can happen during the class-loading (parent not loaded yet). But we ignore this error
        }
        // TODO: Detach all related methods, constants, properties, etc...
        $this->pointer->parent = null;
    }

    /**
     * Configures a new parent class for this one
     *
     * By default, methods are not copied, need to perform by hand
     *
     * @param string|null $newParent New parent class name or null
     */
    public function setParent(?string $newParent = null)
    {
        // If this class has a parent, then we need to detach it first
        if ($this->hasParentClass()) {
            $this->removeParentClass();
        }

        // TODO: If we have a parent, then we need to remove all parent methods first
        // TODO: what to do with methods from grandparents?
        $oldParentClass = $this->getParentClass();
        if ($oldParentClass !== null) {
            foreach ($this->methodTable as $functionName => $functionValue) {
                $functionEntry = $functionValue->getFunctionEntry();
                if ($functionEntry->getScope()->getName() === $oldParentClass->getName()) {
                    $this->methodTable->delete($functionName);
                }
            }
        }
        $this->pointer->parent = $newParent->pointer;
    }

    /**
     * Declares this class as final/non-final
     *
     * @param bool $isFinal True to make class final/false to remove final flag
     */
    public function setFinal(bool $isFinal = true): void
    {
        if ($isFinal) {
            $this->pointer->ce_flags->cdata = ($this->pointer->ce_flags | Core::ZEND_ACC_FINAL);
        } else {
            $this->pointer->ce_flags->cdata = ($this->pointer->ce_flags & (~Core::ZEND_ACC_FINAL));
        }
    }


    /**
     * Declares this class as abstract/non-abstract
     *
     * @param bool $isAbstract True to make current class abstract or false to remove abstract flag
     */
    public function setAbstract(bool $isAbstract = true): void
    {
        if ($isAbstract) {
            $this->pointer->ce_flags->cdata = ($this->pointer->ce_flags | Core::ZEND_ACC_EXPLICIT_ABSTRACT_CLASS);
        } else {
            $this->pointer->ce_flags->cdata = ($this->pointer->ce_flags & (~Core::ZEND_ACC_EXPLICIT_ABSTRACT_CLASS));
            $this->pointer->ce_flags->cdata = ($this->pointer->ce_flags & (~Core::ZEND_ACC_IMPLICIT_ABSTRACT_CLASS));
        }
    }

    /**
     * Sets a new start line for the class in the file
     */
    public function setStartLine(int $newStartLine): void
    {
        if (!$this->isUserDefined()) {
            throw new \ReflectionException('Line can be configured only for user-defined class');
        }
        $this->pointer->info->user->line_start = $newStartLine;
    }

    /**
     * Sets a new end line for the class in the file
     */
    public function setEndLine(int $newEndLine): void
    {
        if (!$this->isUserDefined()) {
            throw new \ReflectionException('Line can be configured only for user-defined class');
        }
        $this->pointer->info->user->line_end = $newEndLine;
    }

    /**
     * Sets a new filename for this class
     */
    public function setFileName(string $newFileName): void
    {
        if (!$this->isUserDefined()) {
            throw new \ReflectionException('File can be configured only for user-defined class');
        }
        $stringEntry = StringEntry::fromString($newFileName);
        $this->pointer->info->user->filename = $stringEntry->pointer;
    }

    /**
     * Returns the list of default properties. Only for non-static ones
     *
     * @return iterable|ReflectionValue[]
     */
    public function getDefaultProperties(): iterable
    {
        $iterator = function () {
            $propertyIndex = 0;
            while ($propertyIndex < $this->pointer->default_properties_count) {
                $value = $this->pointer->default_properties_table[$propertyIndex];
                yield $propertyIndex => ReflectionValue::fromValueEntry($value);
                $propertyIndex++;
            }
        };

        return iterator_to_array($iterator());
    }

    /**
     * Returns the list of default static members. Only for static ones
     *
     * @return iterable|ReflectionValue[]
     */
    public function getDefaultStaticMembers(): iterable
    {
        $iterator = function () {
            $propertyIndex = 0;
            while ($propertyIndex < $this->pointer->default_static_members_count) {
                $value = $this->pointer->default_static_members_table[$propertyIndex];
                yield $propertyIndex => ReflectionValue::fromValueEntry($value);
                $propertyIndex++;
            }
        };

        return iterator_to_array($iterator());
    }

    public function __debugInfo()
    {
        return [
            'name' => $this->getName(),
        ];
    }

    /**
     * Checks if the current class has a parent
     */
    private function hasParentClass(): bool
    {
        return $this->pointer->parent_name !== null;
    }

    /**
     * Performs low-level initialization of fields
     *
     * @param CData $classEntry
     */
    private function initLowLevelStructures(CData $classEntry): void
    {
        $this->pointer         = $classEntry;
        $this->methodTable     = new HashTable(FFI::addr($classEntry->function_table));
        $this->propertiesTable = new HashTable(FFI::addr($classEntry->properties_info));
        $this->constantsTable  = new HashTable(FFI::addr($classEntry->constants_table));
    }
}
