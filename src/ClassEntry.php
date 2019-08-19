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

namespace ZEngine;

use FFI;
use FFI\CData;

class ClassEntry
{
    /**
     * @var HashTable|ValueEntry[]
     */
    public HashTable $functionTable;

    /**
     * @var HashTable|ValueEntry[]
     */
    public HashTable $propertiesTable;

    /**
     * @var HashTable|ValueEntry[]
     */
    public HashTable $constantsTable;

    private CData $pointer;

    public function __construct(CData $pointer)
    {
        $this->pointer         = $pointer;
        $this->functionTable   = new HashTable(FFI::addr($pointer->function_table));
        $this->propertiesTable = new HashTable(FFI::addr($pointer->properties_info));
        $this->constantsTable  = new HashTable(FFI::addr($pointer->constants_table));
    }

    /**
     * Checks if the current class has a parent class
     */
    public function hasParent(): bool
    {
        return $this->pointer->parent !== null;
    }

    /**
     * Returns a parent class entry (if present)
     */
    public function getParent(): ClassEntry
    {
        if (($this->pointer->ce_flags & Core::ZEND_ACC_LINKED)) {
            return new self($this->pointer->parent);
        }

        $parentName = new StringEntry($this->pointer->parent_name);
        $classValue = Core::$classTable->find((string)$parentName);

        return $classValue->getClassEntry();
    }

    /**
     * Configures a new parent class for this one
     *
     * By default, methods are not copied, need to perform by hand
     *
     * @param ClassEntry|null $newParent New parent or null
     */
    public function setParent(?ClassEntry $newParent)
    {
        // TODO: If we have a parent, then we need to remove all parent methods first
        // TODO: what to do with methods from grandparents?
        if ($this->hasParent()) {
            $oldParent = $this->getParent();
            foreach ($this->functionTable as $functionName => $functionValue) {
                $functionEntry = $functionValue->getFunctionEntry();
                if ($functionEntry->getScope()->getName() === $oldParent->getName()) {
                    $this->functionTable->delete($functionName);
                }
            }
        }
        $this->pointer->parent = $newParent->pointer;
    }

    /**
     * Returns the name of class
     */
    public function getName(): string
    {
        return (string)(new StringEntry($this->pointer->name));
    }

    /**
     * Checks if class entry is an interface
     */
    public function isInterface(): bool
    {
        return (bool)($this->pointer->ce_flags & Core::ZEND_ACC_INTERFACE);
    }

    /**
     * Checks if class entry is an interface
     */
    public function isTrait(): bool
    {
        return (bool)($this->pointer->ce_flags & Core::ZEND_ACC_TRAIT);
    }

    /**
     * Checks if class declared as final
     */
    public function isFinal(): bool
    {
        return (bool)($this->pointer->ce_flags & Core::ZEND_ACC_FINAL);
    }

    /**
     * Declare class as final/non-final
     *
     * @param bool $isFinal True to make class final/false to remove final flag
     */
    public function setFinal($isFinal = true): void
    {
        if ($isFinal) {
            $this->pointer->ce_flags->cdata = ($this->pointer->ce_flags | Core::ZEND_ACC_FINAL);
        } else {
            $this->pointer->ce_flags->cdata = ($this->pointer->ce_flags & (~Core::ZEND_ACC_FINAL));
        }
    }

    /**
     * Checks if class explicitly declared as abstract
     */
    public function isAbstract(): bool
    {
        return (bool)($this->pointer->ce_flags & Core::ZEND_ACC_EXPLICIT_ABSTRACT_CLASS);
    }

    /**
     * Declare class as abstract/non-abstract
     *
     * @param bool $isAbstract True to make class abstract/false to remove abstrct flag
     */
    public function setAbstract($isAbstract = true): void
    {
        if ($isAbstract) {
            $this->pointer->ce_flags->cdata = ($this->pointer->ce_flags | Core::ZEND_ACC_EXPLICIT_ABSTRACT_CLASS);
        } else {
            $this->pointer->ce_flags->cdata = ($this->pointer->ce_flags & (~Core::ZEND_ACC_EXPLICIT_ABSTRACT_CLASS));
            $this->pointer->ce_flags->cdata = ($this->pointer->ce_flags & (~Core::ZEND_ACC_IMPLICIT_ABSTRACT_CLASS));
        }
    }

    public function getDefaultPropertiesCount(): int
    {
        return $this->pointer->default_properties_count;
    }

    public function getDefaultStaticMembersCount(): int
    {
        return $this->pointer->default_static_members_count;
    }

    /**
     * Returns the list of default properties. Only for non-static ones
     *
     * @return iterable|ValueEntry[]
     */
    public function getDefaultProperties(): iterable
    {
        $iterator = function () {
            $propertyIndex = 0;
            while ($propertyIndex < $this->pointer->default_properties_count) {
                $value = $this->pointer->default_properties_table[$propertyIndex];
                yield $propertyIndex => new ValueEntry($value);
                $propertyIndex++;
            }
        };

        return iterator_to_array($iterator());
    }

    /**
     * Returns the list of default static members. Only for static ones
     *
     * @return iterable|ValueEntry[]
     */
    public function getDefaultStaticMembers(): iterable
    {
        $iterator = function () {
            $propertyIndex = 0;
            while ($propertyIndex < $this->pointer->default_static_members_count) {
                $value = $this->pointer->default_static_members_table[$propertyIndex];
                yield $propertyIndex => new ValueEntry($value);
                $propertyIndex++;
            }
        };

        return iterator_to_array($iterator());
    }

    public function __debugInfo()
    {
        return [
            'name'            => $this->getName(),
            'functionTable'   => $this->functionTable,
            'propertiesTable' => $this->propertiesTable,
            'constantsTable'  => $this->constantsTable
        ];
    }
}
