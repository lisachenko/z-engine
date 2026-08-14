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

use FFI\CData;
use ReflectionClass;
use ZEngine\Core;
use ZEngine\Generated\zend_refcounted_h;
use ZEngine\Generated\zend_string;
use ZEngine\Reflection\ReflectionValue;

/**
 * This class wraps PHP's zend_string structure and provide an API for working with it
 *
 * Memory ownership contract (see docs/long-running.md for the full model):
 *
 *  - `new StringEntry($php)` is an OWNING construction: it takes one reference on the
 *    argument's zend_string, released automatically on destruction or via release().
 *    Interned strings are immortal engine values - no reference is taken for them and
 *    every release is a no-op.
 *  - fromCData() is BORROWED (no addref): the pointer is valid only for as long as the
 *    engine owner keeps the string alive. All construction paths store zend_string*.
 *  - fromString() mints a fresh OWNED refcount-1 request-lifetime string; persistent()
 *    mints an owned malloc-backed string for sinks inside persistent engine structures
 *    (internal classes). Neither aliases caller memory, which makes them the only safe
 *    sources for pointers stored into engine structures - hand the reference over with
 *    transferReferenceOwnership() so the engine sink releases it instead of the wrapper.
 *  - releaseReference() drops exactly one engine reference with full engine semantics
 *    (interned/immutable untouched, destruction at zero via rc_dtor_func, persistent
 *    blocks never freed with the request allocator). Use it only when editing a reference
 *    owned by an engine structure (eg removing trait names); wrapper-owned references are
 *    handled by release().
 *  - Never free a zend_string through the FFI allocator: request strings belong to the
 *    Zend memory manager and persistent ones to the engine's malloc bookkeeping.
 *
 * struct _zend_string {
 *   zend_refcounted_h gc;
 *   zend_ulong        h;                // hash value
 *   size_t            len;
 *   char              val[1];
 * };
 */
class StringEntry implements ReferenceCountedInterface
{
    use ReferenceCountedTrait;
    use ReleasableTrait;

    /**
     * @var zend_string Typed view of the wrapped engine string; the runtime value is
     *                  the raw FFI\CData handle (see stubs/zend-engine-structs.php)
     */
    private object $pointer;

    /**
     * Creates a string entry from the PHP string
     *
     * The entry holds its own reference on the engine string (unless it is interned), so the
     * wrapped pointer stays valid for the whole wrapper lifetime; release()/__destruct drops it.
     *
     * The value is deliberately never named in the body: it is read back out of the
     * engine frame this very constructor runs in (argument slot 0), which is the only way
     * to reach the caller's own zval instead of a copy. Removing the parameter would
     * remove the zval the constructor is built to capture.
     *
     * @phpstan-ignore constructor.unusedParameter (captured from the frame's argument slot 0)
     */
    public function __construct(string $value)
    {
        // This code is used to extract a Zval for our $value argument and use its internal pointer.
        // The pointer is stored as zend_string* (not as a dereferenced struct), matching every
        // other construction path - the old struct form broke getStringValue() on constructed
        // entries and could not be passed to engine functions expecting zend_string*
        $valueArgument = Core::$executor->getExecutionState()->getArgument(0);
        $this->pointer = Core::cast(zend_string::class, $valueArgument->getRawString());
        if (!$this->isInterned()) {
            $this->incrementReferenceCount();
            $this->ownsReference = true;
        }
    }

    /**
     * Creates a string entry from the zend_string structure (borrowed, does not addref)
     *
     * @param CData|zend_string $stringPointer Pointer to the structure
     */
    public static function fromCData(object $stringPointer): StringEntry
    {
        /** @var StringEntry $stringEntry */
        $stringEntry = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        /** @var zend_string $stringPointer Narrowed to the stub view at the owning boundary */
        $stringEntry->pointer = $stringPointer;

        return $stringEntry;
    }

    /**
     * Mints a fresh, owned request-lifetime zend_string with refcount 1
     *
     * Unlike the constructor (which references the string of the argument zval), the result
     * never aliases caller memory, which makes it safe to hand over to engine sinks via
     * transferReferenceOwnership(). zend_string_concat2 is used because the whole
     * zend_string_init/alloc family is inline-only and not exported by the engine.
     */
    public static function fromString(string $value): StringEntry
    {
        $rawString = Core::call('zend_string_concat2', $value, strlen($value), '', 0);
        assert($rawString instanceof CData);

        $stringEntry                = static::fromCData($rawString);
        $stringEntry->ownsReference = true;

        return $stringEntry;
    }

    /**
     * Mints an owned persistent (malloc-backed) zend_string with refcount 1
     *
     * Persistent strings are required for sinks inside persistent engine structures
     * (internal classes and their members), which the engine releases with the persistent
     * allocator. The struct is built manually because no persistent string constructor is
     * exported; the layout is verified at boot by the engine layout checks.
     */
    public static function persistent(string $value): StringEntry
    {
        $length    = strlen($value);
        $valOffset = Core::type(zend_string::class)->getStructFieldOffset('val');

        $buffer = Core::new('char[' . ($valOffset + $length + 1) . ']', false, true);
        $string = Core::cast(zend_string::class, $buffer);

        $string->gc->refcount     = 1;
        $string->gc->u->type_info = Core::engineConstant('GC_STRING') | Core::engineConstant('GC_PERSISTENT');
        $string->len              = $length;
        $valPointer               = Core::cast('char *', $buffer) + $valOffset;
        assert($valPointer instanceof CData);
        Core::memcpy($valPointer, $value . "\0", $length + 1);
        $hash = Core::call('zend_string_hash_func', $string);
        assert(is_int($hash));
        $string->h = $hash;

        $stringEntry                = static::fromCData($string);
        $stringEntry->ownsReference = true;

        return $stringEntry;
    }

    /**
     * Mints an owned persistent interned-style (malloc-backed, immutable) zend_string
     *
     * Unlike persistent(), the GC_IMMUTABLE flag makes every engine consumer treat the
     * string exactly like an interned one: zvals hold it without refcounting and
     * mutation paths copy-on-write into request memory instead of touching this block.
     * Required for strings reachable from userland values that outlive the request
     * (persistent object properties): a refcounted persistent string reaching refcount
     * zero would be freed with the request allocator and corrupt the malloc heap.
     *
     * The string is never registered in the engine's interned tables, so equality
     * against a real interned string falls back to a content compare - same contract
     * as opcache SHM strings in processes that attach without the interning pass.
     */
    public static function persistentInterned(string $value): StringEntry
    {
        $stringEntry = static::persistent($value);
        $pointer     = $stringEntry->pointer;

        $pointer->gc->u->type_info |= Core::engineConstant('GC_IMMUTABLE');
        // Interned strings live outside refcounting: the engine never addrefs or
        // releases them, the conventional refcount value for such headers is 2
        $pointer->gc->refcount = 2;
        // The wrapper must not treat this as an owned engine reference either
        $stringEntry->ownsReference = false;

        return $stringEntry;
    }

    /**
     * Returns raw C value entry
     *
     * @return zend_string
     */
    public function getRawValue(): object
    {
        return $this->pointer;
    }

    /**
     * Returns a hash for given string
     */
    public function getHash(): int
    {
        return $this->pointer->h;
    }

    /**
     * Returns a string length
     */
    public function getLength(): int
    {
        return $this->pointer->len;
    }

    /**
     * Returns a PHP representation of engine string
     */
    public function getStringValue(): string
    {
        $entry = ReflectionValue::newEntry(ReflectionValue::IS_STRING, StructArray::at($this->pointer));
        $entry->getNativeValue($realString);
        $entry->release();

        return $realString;
    }

    /**
     * Takes one more engine reference on this string and returns the very same entry
     *
     * No copy is made and no new wrapper is produced: this is zend_string_copy(), which shares
     * the existing string by bumping its counter, and is what a call site needs when an engine
     * structure starts holding the string too. Interned and other immutable strings are handled
     * exactly as the engine does - they are immortal, so no reference is taken - which is what
     * separates this from the raw incrementReferenceCount() primitive underneath (that one
     * refuses immutable payloads outright).
     *
     * @see zend_string.h::zend_string_copy function
     */
    public function addReference(): self
    {
        if (!$this->isInterned()) {
            $this->incrementReferenceCount();
        }

        return $this;
    }

    /**
     * Creates a copy of string value
     *
     * @see zend_string.h::zend_string_copy function
     *
     * @return self
     *
     * @deprecated Use addReference() instead - this method never copied anything
     */
    #[\Deprecated(message: 'use StringEntry::addReference() instead - this method never copied anything', since: '8.4.1')]
    public function copy(): self
    {
        return $this->addReference();
    }

    /**
     * @inheritDoc
     */
    protected function doRelease(bool $ownsReference, bool $ownsContainer): void
    {
        if ($ownsReference) {
            // Full engine semantics (interned no-op, rc_dtor_func at refcount zero),
            // never an FFI-level free of engine memory
            $this->releaseReference();
        }
    }

    /**
     * Alias to check if this string is interned (aka immutable)
     *
     * @return bool
     */
    public function isInterned(): bool
    {
        return $this->isImmutable();
    }

    /**
     * Checks if this string is a permanent interned string (lives for the process
     * lifetime, eg opcache SHM or startup interning) rather than a request-interned one
     *
     * @see zend_string.h:IS_STR_PERMANENT
     */
    public function isPermanent(): bool
    {
        return $this->hasGcFlag(Core::engineConstant('IS_STR_PERMANENT'));
    }

    /**
     * Checks whether this string carries the engine's fast class-entry cache slot
     *
     * A permanent interned class-name string (opcache shared memory, preload, engine
     * startup) reuses its refcount field as the byte offset of a per-request slot in the
     * map-ptr area, where the engine memoizes the class entry the name resolves to. Every
     * class lookup consults that slot BEFORE the class table, so a name whose cache is
     * populated never reaches the table again in this request.
     *
     * @see zend_types.h:ZSTR_HAS_CE_CACHE/ZSTR_VALID_CE_CACHE
     */
    public function hasClassEntryCache(): bool
    {
        if (!$this->hasGcFlag(Core::engineConstant('IS_STR_CLASS_NAME_MAP_PTR'))) {
            return false;
        }
        $mapPointerBase = Core::$compiler->getMapPointerBaseAddress();
        if ($mapPointerBase === 0) {
            return false;
        }
        // ZSTR_VALID_CE_CACHE(): the slot must be inside the area allocated so far
        $slotIndex = intdiv($this->getReferenceCount() - 1, Core::sizeOfType('void *'));

        return $slotIndex < Core::$compiler->getMapPointerLast();
    }

    /**
     * Points the engine's fast class-entry cache for this class name at the given class entry
     *
     * The counterpart of the engine's own ZSTR_SET_CE_CACHE(), needed whenever the class
     * entry a name resolves to is replaced in the class table (the opcache copy-out): call
     * sites compiled into cached scripts read the memoized entry, not the table, so without
     * this refresh they would keep dispatching the shared-memory class entry.
     *
     * @param CData $classEntry zend_class_entry the name must resolve to from now on
     *
     * @see zend_types.h:ZSTR_SET_CE_CACHE
     */
    public function setCachedClassEntry(object $classEntry): void
    {
        if (!$this->hasClassEntryCache()) {
            throw new \LogicException('This string does not carry an engine class-entry cache slot');
        }
        $slotAddress  = Core::$compiler->getMapPointerBaseAddress() + $this->getReferenceCount();
        $cacheSlot    = Core::pointerAtAddress('zend_class_entry **', $slotAddress);
        $cacheSlot[0] = $classEntry;
    }

    /**
     * This method returns a dumpable representation of internal value to prevent segfault
     */
    public function __debugInfo(): array
    {
        return [
            'value'    => $this->getStringValue(),
            'length'   => $this->getLength(),
            'refcount' => $this->getReferenceCount(),
            'hash'     => $this->getHash(),
        ];
    }

    /**
     * @inheritDoc
     *
     * @return zend_refcounted_h
     */
    protected function getGC(): object
    {
        return $this->pointer->gc;
    }
}
