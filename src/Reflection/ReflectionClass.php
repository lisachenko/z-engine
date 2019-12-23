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

use Closure;
use FFI\CData;
use ReflectionClass as NativeReflectionClass;
use ZEngine\ClassExtension\ObjectCastInterface;
use ZEngine\ClassExtension\ObjectCompareValuesInterface;
use ZEngine\ClassExtension\ObjectCreateInterface;
use ZEngine\ClassExtension\ObjectDoOperationInterface;
use ZEngine\Core;
use ZEngine\Type\ClosureEntry;
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

    /**
     * Stores all allocated zend_object_handler pointers per class
     */
    private static array $objectHandlers = [];

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
        $classEntry = $classEntryValue->getRawClass();
        $this->initLowLevelStructures($classEntry);
    }

    /**
     * Creates a reflection from the zend_class_entry structure
     *
     * @param CData $classEntry Pointer to the structure
     *
     * @return ReflectionClass
     */
    public static function fromCData(CData $classEntry): ReflectionClass
    {
        /** @var ReflectionClass $reflectionClass */
        $reflectionClass = (new NativeReflectionClass(static::class))->newInstanceWithoutConstructor();
        $reflectionClass->initLowLevelStructures($classEntry);
        $classNameValue = StringEntry::fromCData($classEntry->name);
        try {
            call_user_func([$reflectionClass, 'parent::__construct'], $classNameValue->getStringValue());
        } catch (\ReflectionException $e) {
            // This can happen during the class-loading. But we still can work with it.
        }

        return $reflectionClass;
    }

    /**
     * @inheritDoc
     */
    public function getName()
    {
        return StringEntry::fromCData($this->pointer->name)->getStringValue();
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
            $interfaceNameValue = StringEntry::fromCData($rawInterfaceName);
            $interfaceNames[]   = $interfaceNameValue->getStringValue();
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
     *
     * @see zend_inheritance.c:zend_do_implement_interface() function implementation for details
     */
    public function addInterfaces(string ...$interfaceNames): void
    {
        $availableInterfaces = $this->getInterfaceNames();
        $interfacesToAdd     = array_values(array_diff($interfaceNames, $availableInterfaces));
        $numInterfacesToAdd  = count($interfacesToAdd);
        $totalInterfaces     = count($availableInterfaces);
        $numResultInterfaces = $totalInterfaces + $numInterfacesToAdd;

        // Memory should be non-owned to keep it live more that $memory variable in this method.
        // If this class is internal then we should use persistent memory
        // If this class is user-defined and we are not in CLI, then use persistent memory, otherwise non-persistent
        $isPersistent = $this->isInternal() || PHP_SAPI !== 'cli';
        $memory       = Core::new("zend_class_entry *[$numResultInterfaces]", false, $isPersistent);

        $itemsSize = Core::sizeof(Core::type('zend_class_entry *'));
        if ($totalInterfaces > 0) {
            Core::memcpy($memory, $this->pointer->interfaces, $itemsSize * $totalInterfaces);
        }
        for ($position = $totalInterfaces, $index = 0; $index < $numInterfacesToAdd; $position++, $index++) {
            $interfaceName = $interfacesToAdd[$index];
            if (!interface_exists($interfaceName)) {
                throw new \ReflectionException("Interface {$interfaceName} was not found");
            }
            $classValueEntry   = Core::$executor->classTable->find(strtolower($interfaceName));
            $interfaceClass    = $classValueEntry->getRawClass();
            $memory[$position] = $interfaceClass;
        }

        // As we don't have realloc methods in PHP, we can free non-persistent memory to prevent leaks
        if ($totalInterfaces > 0 && !$isPersistent) {
            Core::free($this->pointer->interfaces);
        }
        $this->pointer->interfaces = Core::cast('zend_class_entry **', Core::addr($memory));

        // We should also add ZEND_ACC_RESOLVED_INTERFACES explicitly with first interface
        if ($totalInterfaces === 0 && $numInterfacesToAdd > 0) {
            $this->pointer->ce_flags |= Core::ZEND_ACC_RESOLVED_INTERFACES;
        }
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

        // Memory should be non-owned to keep it live more that $memory variable in this method.
        // If this class is internal then we should use persistent memory
        // If this class is user-defined and we are not in CLI, then use persistent memory, otherwise non-persistent
        $isPersistent = $this->isInternal() || PHP_SAPI !== 'cli';

        // If we remove all interfaces then just clear $this->pointer->interfaces field
        if ($numResultInterfaces === 0) {
            if ($totalInterfaces > 0 && !$isPersistent) {
                Core::free($this->pointer->interfaces);
            }
            // We should also clean ZEND_ACC_RESOLVED_INTERFACES
            $this->pointer->interfaces = null;
            $this->pointer->ce_flags &= (~ Core::ZEND_ACC_RESOLVED_INTERFACES);
        } else {
            // Allocate non-owned memory, either persistent (for internal classes) or not (for user-defined)
            $memory = Core::new("zend_class_entry *[$numResultInterfaces]", false, $isPersistent);
            for ($index = 0, $destIndex = 0; $index < $this->pointer->num_interfaces; $index++) {
                if (!isset($indexesToRemove[$index])) {
                    $memory[$destIndex++] = $this->pointer->interfaces[$index];
                }
            }
            if ($totalInterfaces > 0 && !$isPersistent) {
                Core::free($this->pointer->interfaces);
            }
            $this->pointer->interfaces = Core::cast('zend_class_entry **', Core::addr($memory));
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

        return ReflectionMethod::fromCData($functionEntry->getRawFunction());
    }

    /**
     * @inheritDoc
     * @return ReflectionMethod[]
     */
    public function getMethods($filter = null)
    {
        $methods = [];
        foreach ($this->methodTable as $methodEntryValue) {
            $functionEntry = $methodEntryValue->getRawFunction();
            if (!isset($filter) || ($functionEntry->common->fn_flags & $filter)) {
                $methods[] = ReflectionMethod::fromCData($functionEntry);
            }
        }

        return $methods;
    }

    /**
     * Adds a new method to the class in runtime
     */
    public function addMethod(string $methodName, \Closure $method): ReflectionMethod
    {
        $closureEntry = new ClosureEntry($method);
        // This line will make this closure live until the end of script/request
        $closureEntry->getClosureObjectEntry()->incrementReferenceCount();
        $closureEntry->setCalledScope($this->name);

        // TODO: replace with ReflectionFunction instead of low-level structures
        $rawFunction  = $closureEntry->getRawFunction();
        $funcName     = (new StringEntry($methodName))->getRawValue();
        $rawFunction->common->function_name = $funcName;

        // Adjust the scope of our function to our class
        $classScopeValue = Core::$executor->classTable->find(strtolower($this->name));
        $rawFunction->common->scope = $classScopeValue->getRawClass();

        // Clean closure flag
        $rawFunction->common->fn_flags &= (~Core::ZEND_ACC_CLOSURE);

        $isPersistent = $this->isInternal() || PHP_SAPI !== 'cli';
        $refMethod    = $this->addRawMethod($methodName, $rawFunction, $isPersistent);
        $refMethod->setPublic();

        return $refMethod;
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
        $traitsToAdd     = array_values(array_diff($traitNames, $availableTraits));
        $numTraitsToAdd  = count($traitsToAdd);
        $totalTraits     = count($availableTraits);
        $numResultTraits = $totalTraits + $numTraitsToAdd;

        // Memory should be non-owned to keep it live more that $memory variable in this method.
        // If this class is internal then we should use persistent memory
        // If this class is user-defined and we are not in CLI, then use persistent memory, otherwise non-persistent
        $isPersistent = $this->isInternal() || PHP_SAPI !== 'cli';
        $memory       = Core::new("zend_class_name [$numResultTraits]", false, $isPersistent);

        $itemsSize = Core::sizeof(Core::type('zend_class_name'));
        if ($totalTraits > 0) {
            Core::memcpy($memory, $this->pointer->trait_names, $itemsSize * $totalTraits);
        }
        for ($position = $totalTraits, $index = 0; $index < $numTraitsToAdd; $position++, $index++) {
            $traitName   = $traitsToAdd[$index];
            $lcTraitName = strtolower($traitName);
            $name        = new StringEntry($traitName);
            $lcName      = new StringEntry($lcTraitName);

            $memory[$position]->name    = $name->getRawValue();
            $memory[$position]->lc_name = $lcName->getRawValue();
        }
        // As we don't have realloc methods in PHP, we can free non-persistent memory to prevent leaks
        if ($totalTraits > 0 && !$isPersistent) {
            Core::free($this->pointer->trait_names);
        }

        $this->pointer->trait_names = Core::cast('zend_class_name *', Core::addr($memory));
        $this->pointer->num_traits  = $numResultTraits;
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

        // Memory should be non-owned to keep it live more that $memory variable in this method.
        // If this class is internal then we should use persistent memory
        // If this class is user-defined and we are not in CLI, then use persistent memory, otherwise non-persistent
        $isPersistent = $this->isInternal() || PHP_SAPI !== 'cli';

        if ($numResultTraits > 0) {
            $memory = Core::new("zend_class_name[$numResultTraits]", false, $isPersistent);
        } else {
            $memory = null;
        }
        for ($index = 0, $destIndex = 0; $index < $totalTraits; $index++) {
            $traitNameStruct = $this->pointer->trait_names[$index];
            if (!isset($indexesToRemove[$index])) {
                $memory[$destIndex++] = $traitNameStruct;
            } else {
                // Clean strings to prevent memory leaks
                StringEntry::fromCData($traitNameStruct->name)->release();
                StringEntry::fromCData($traitNameStruct->lc_name)->release();
            }
        }
        if ($totalTraits > 0 && !$isPersistent) {
            Core::free($this->pointer->trait_names);
        }
        if ($numResultTraits > 0) {
            $this->pointer->trait_names = Core::cast('zend_class_name *', Core::addr($memory));
        } else {
            $this->pointer->trait_names = null;
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

        $parentNameValue = StringEntry::fromCData($rawParentName);
        $classReflection = new ReflectionClass($parentNameValue->getStringValue());

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
            $methodsToRemove = [];
            foreach ($this->getMethods() as $reflectionMethod) {
                $methodClass     = $reflectionMethod->getDeclaringClass();
                $methodClassName = $methodClass->getName();
                $isParentMethod  = $parentClass->getName() === $methodClassName;
                $isGrandMethod   = $parentClass->isSubclassOf($methodClassName);

                if ($isParentMethod || $isGrandMethod) {
                    $methodsToRemove[] = $reflectionMethod->getName();
                }
            }
            if (count($methodsToRemove) > 0) {
                $this->removeMethods(...$methodsToRemove);
            }
        } catch (\ReflectionException $e) {
            // This can happen during the class-loading (parent not loaded yet). But we ignore this error
        }
        // TODO: Detach all related constants, properties, etc...
        $this->pointer->parent = null;
    }

    /**
     * Configures a new parent class for this one
     *
     * @param string $newParent New parent class name
     */
    public function setParent(string $newParent)
    {
        // If this class has a parent, then we need to detach it first
        if ($this->hasParentClass()) {
            $this->removeParentClass();
        }

        // Look for the parent zend_class_entry
        $parentClassValue = Core::$executor->classTable->find(strtolower($newParent));
        if ($parentClassValue === null) {
            throw new \ReflectionException("Class {$newParent} was not found");
        }

        // Call API to reduce the boilerplate code
        Core::call('zend_do_inheritance_ex', $this->pointer, $parentClassValue->getRawClass(), 0);
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
        $stringEntry = new StringEntry($newFileName);
        $this->pointer->info->user->filename = $stringEntry->getRawValue();
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

    /**
     * @inheritDoc
     * @return ReflectionClassConstant
     */
    public function getReflectionConstant($name)
    {
        $constantEntry = $this->constantsTable->find($name);
        if ($constantEntry === null) {
            throw new \ReflectionException("Constant {$name} does not exist");
        }
        $constantPtr = Core::cast('zend_class_constant *', $constantEntry->getRawPointer());

        return ReflectionClassConstant::fromCData($constantPtr, $name);
    }

    /**
     * Installs user-defined object handlers for given class to control extra-features of this class
     */
    public function installExtensionHandlers(): void
    {
        if (!$this->implementsInterface(ObjectCreateInterface::class)) {
            $str = 'Class ' . $this->name . ' should implement at least ObjectCreateInterface to setup user handlers';
            throw new \ReflectionException($str);
        }

        $handler = parent::getMethod('__init')->getClosure();
        $this->setCreateObjectHandler($handler);

        if ($this->implementsInterface(ObjectCastInterface::class)) {
            $handler = parent::getMethod('__cast')->getClosure();
            $this->setCastObjectHandler($handler);
        }

        if ($this->implementsInterface(ObjectDoOperationInterface::class)) {
            $handler = parent::getMethod('__doOperation')->getClosure();
            $this->setDoOperationHandler($handler);
        }

        if ($this->implementsInterface(ObjectCompareValuesInterface::class)) {
            $handler = parent::getMethod('__compare')->getClosure();
            $this->setCompareValuesHandler($handler);
        }
    }

    public function __debugInfo()
    {
        return [
            'name' => $this->getName(),
        ];
    }

    /**
     * Installs the cast_object handler for current class
     *
     * @param Closure $handler Callback function (object $instance, int $typeTo): mixed;
     *
     * @see ObjectCastInterface
     */
    public function setCastObjectHandler(Closure $handler): void
    {
        $handlers = self::getObjectHandlers($this->pointer);

        $handlers->cast_object = function (CData $objectZval, CData $returnZval, int $castType) use ($handler) {
            ReflectionValue::fromValueEntry($objectZval)->getNativeValue($objectInstance);
            $result = $handler($objectInstance, $castType);
            ReflectionValue::fromValueEntry($returnZval)->setNativeValue($result);

            return Core::SUCCESS;
        };
    }

    /**
     * Installs the compare handler for current class
     *
     * @param Closure $handler Callback function ($left, $right): int;
     *
     * @see ObjectCompareValuesInterface
     */
    public function setCompareValuesHandler(Closure $handler): void
    {
        $handlers = self::getObjectHandlers($this->pointer);

        $handlers->compare = function (CData $returnZval, CData $leftZval, CData $rightZval) use ($handler) {
            ReflectionValue::fromValueEntry($leftZval)->getNativeValue($leftValue);
            ReflectionValue::fromValueEntry($rightZval)->getNativeValue($rightValue);
            $result = $handler($leftValue, $rightValue);
            ReflectionValue::fromValueEntry($returnZval)->setNativeValue($result);

            return Core::SUCCESS;
        };
    }

    /**
     * Installs the do_operation handler for current class
     *
     * @param Closure $handler Callback function (object $instance, int $typeTo);
     *
     * @see ObjectDoOperationInterface
     */
    public function setDoOperationHandler(Closure $handler): void
    {
        $handlers = self::getObjectHandlers($this->pointer);

        $handlers->do_operation = function (int $opcode, $resultZval, $leftZval, $rightZval) use ($handler) {
            ReflectionValue::fromValueEntry($leftZval)->getNativeValue($leftValue);
            ReflectionValue::fromValueEntry($rightZval)->getNativeValue($rightValue);
            $result = $handler($opcode, $leftValue, $rightValue);

            ReflectionValue::fromValueEntry($resultZval)->setNativeValue($result);

            return Core::SUCCESS;
        };
    }

    /**
     * Installs the create_object handler, this handler is required for all other handlers
     *
     * @param Closure $handler Callback function (CData $classType, Closure $initializer): CData
     *
     * @see ObjectCreateInterface
     */
    public function setCreateObjectHandler(Closure $handler): void
    {
        $currentHandler = $this->pointer->create_object;
        $initializer    = static function (CData $classType) use ($currentHandler) {
            if ($currentHandler === null) {
                $object = self::newInstanceRaw($classType);
            } else {
                $object = $currentHandler($classType);
            }

            return $object;
        };

        // User handlers are only allowed with std_object_handler (when create_object handler is empty)
        if ($currentHandler === null) {
            self::allocateClassObjectHandlers($this->getName());
        }

        $this->pointer->create_object = static function (CData $classType) use ($handler, $initializer) {
            return $handler($classType, $initializer);
        };
    }

    /**
     * Installs the handler when another class implements current interface
     *
     * @param Closure $handler Callback function (ReflectionClass $reflectionClass)
     */
    public function setInterfaceGetsImplementedHandler(Closure $handler): void
    {
        if (!$this->isInterface()) {
            throw new \LogicException("Interface implemented handler can be installed only for interfaces");
        }

        $this->pointer->interface_gets_implemented = function (CData $interfaceType, CData $classType) use ($handler) {
            $refClass = ReflectionClass::fromCData($classType);
            $handler($refClass);

            return Core::SUCCESS;
        };

        // At the end of request we should clear this callback to prevent segmentation fault on subsequent requests
        // TODO: Implement better global clean-up procedure to deal with modified entries
        register_shutdown_function(function () {
            $this->pointer->interface_gets_implemented = null;
        });
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
        $this->methodTable     = new HashTable(Core::addr($classEntry->function_table));
        $this->propertiesTable = new HashTable(Core::addr($classEntry->properties_info));
        $this->constantsTable  = new HashTable(Core::addr($classEntry->constants_table));
    }

    /**
     * Adds a low-level function(method) to the class
     *
     * @param string $methodName Method name to use
     * @param CData  $rawFunction zend_function instance
     * @param bool   $isPersistent Whether this method is persistent or not
     *
     * @return ReflectionMethod
     */
    private function addRawMethod(string $methodName, CData $rawFunction, bool $isPersistent = true): ReflectionMethod
    {
        $valueEntry = ReflectionValue::newEntry(ReflectionValue::IS_PTR, $rawFunction, $isPersistent);
        $this->methodTable->add(strtolower($methodName), $valueEntry);

        $refMethod = ReflectionMethod::fromCData($rawFunction);

        return $refMethod;
    }

    /**
     * Creates a new instance of zend_object.
     *
     * This method is useful within create_object handler
     *
     * @param CData $classType zend_class_entry type to create
     *
     * @return CData Instance of zend_object *
     * @see zend_objects.c:zend_objects_new
     */
    private static function newInstanceRaw(CData $classType): CData
    {
        $objectSize = Core::sizeof(Core::type('zend_object'));
        $totalSize  = $objectSize + self::getObjectPropertiesSize($classType);
        $memory     = Core::new("char[{$totalSize}]", false);
        $object     = Core::cast('zend_object *', $memory);

        Core::call('zend_object_std_init', $object, $classType);
        $object->handlers = self::getObjectHandlers($classType);
        Core::call('object_properties_init', $object, $classType);

        return $object;
    }

    /**
     * Returns the size of memory required for storing properties for a given class type
     *
     * @param CData $classType zend_class_entry type to get object property size
     *
     * @see zend_objects_API.h:zend_object_properties_size
     */
    private static function getObjectPropertiesSize(CData $classType): int
    {
        $zvalSize  = Core::sizeof(Core::type('zval'));
        $useGuards = (bool) ($classType->ce_flags & Core::ZEND_ACC_USE_GUARDS);

        $totalSize = $zvalSize * ($classType->default_properties_count - ($useGuards ? 0 : 1));

        return $totalSize;
    }

    /**
     * Returns a pointer to the zend_object_handlers for given zend_class_entry
     *
     * We always create our own object handlers structure to have an ability to adjust callbacks in runtime,
     * otherwise it is impossible because object handlers field is declared as "const"
     *
     * @param CData $classType zend_class_entry type to get object handlers
     */
    private static function getObjectHandlers(CData $classType): CData
    {
        $className = (StringEntry::fromCData($classType->name)->getStringValue());
        if (!isset(self::$objectHandlers[$className])) {
            throw new \RuntimeException(
                'Object handlers for class ' . $className . ' are not configured.' . PHP_EOL .
                'Have you installed the create_object handler first?'
            );
        }

        return self::$objectHandlers[$className];
    }

    /**
     * Allocates a new zend_object_handlers structure for class as a copy of std_object_handlers
     *
     * @param string $className Class name to use
     */
    private static function allocateClassObjectHandlers(string $className): void
    {
        $handlers    = Core::new('zend_object_handlers', false, true);
        $stdHandlers = Core::getStandardObjectHandlers();
        Core::memcpy($handlers, $stdHandlers, Core::sizeof($stdHandlers));

        self::$objectHandlers[$className] = Core::addr($handlers);
    }
}
