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

namespace ZEngine\Type;

use FFI;
use FFI\CData;
use IteratorAggregate;
use Traversable;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionValue;

class HashTable implements IteratorAggregate
{
    private const HASH_UPDATE          = (1 << 0);
    private const HASH_ADD             = (1 << 1);
    private const HASH_UPDATE_INDIRECT = (1 << 2);
    private const HASH_ADD_NEW         = (1 << 3);
    private const HASH_ADD_NEXT        = (1 << 4);

    public CData $value;

    public function __construct(CData $hashInstance)
    {
        $this->value = $hashInstance;
    }

    /**
     * Retrieve an external iterator
     *
     * @return Traversable An instance of an object implementing <b>Iterator</b> or <b>Traversable</b>
     */
    public function getIterator()
    {
        $iterator = function () {
            $index = 0;
            while ($index < $this->value->nNumOfElements) {
                $item = $this->value->arData[$index];
                $index++;
                if ($item->val->u1->v->type === ReflectionValue::IS_UNDEF) {
                    continue;
                }
                $key = $item->key !== null ? StringEntry::fromCData($item->key)->getStringValue() : null;
                yield $key => ReflectionValue::fromValueEntry($item->val);
            }
        };

        return $iterator();
    }

    /**
     * Performs search by key in the hashtable
     *
     * @param string $key Key to find
     *
     * @return ReflectionValue|null Value or null if not found
     */
    public function find(string $key): ?ReflectionValue
    {
        $stringEntry = new StringEntry($key);
        $pointer     = Core::call('zend_hash_find', $this->value, FFI::addr($stringEntry->getRawValue()));

        if ($pointer !== null) {
            $pointer = ReflectionValue::fromValueEntry($pointer);
        }

        return $pointer;
    }

    /**
     * Deletes a value by key from the hashtable
     *
     * @param string $key Key in the hash to delete
     */
    public function delete(string $key): void
    {
        $stringEntry = new StringEntry($key);
        $result      = Core::call('zend_hash_del', $this->value, FFI::addr($stringEntry->getRawValue()));
        if ($result === Core::FAILURE) {
            throw new \RuntimeException("Can not delete an item with key {$key}");
        }
    }

    /**
     * Adds new value to the HashTable
     *
     * @param string $key Key to use
     * @param CData  $value
     */
    public function add(string $key, ReflectionValue $value): void
    {
        $stringEntry = new StringEntry($key);
        $result      = Core::call(
            'zend_hash_add_or_update',
            $this->value,
            FFI::addr($stringEntry->getRawValue()),
            $value->getRawValue(),
            self::HASH_ADD_NEW
        );
        if ($result === Core::FAILURE) {
            throw new \RuntimeException("Can not add an item with key {$key}");
        }
    }

    public function __debugInfo()
    {
        return iterator_to_array($this->getIterator());
    }
}
