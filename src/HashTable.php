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

namespace ZEngine;

use FFI;
use FFI\CData;
use IteratorAggregate;
use Traversable;

class HashTable implements IteratorAggregate
{
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
                $key = $item->key !== null ? (string) (new StringEntry($item->key)) : null;
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
        $pointer = Core::call('zend_hash_find', $this->value, FFI::addr(StringEntry::fromString($key)->pointer));

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
        $key    = strtolower($key);
        $result = Core::call('zend_hash_del', $this->value, FFI::addr(StringEntry::fromString($key)->pointer));
        if ($result === Core::FAILURE) {
            throw new \RuntimeException("Can not delete an item with key {$key}");
        }
    }

    public function __debugInfo()
    {
        return iterator_to_array($this->getIterator());
    }
}
