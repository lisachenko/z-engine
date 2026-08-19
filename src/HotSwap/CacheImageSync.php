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

namespace ZEngine\HotSwap;

use ZEngine\Core;
use ZEngine\OpCache\ImageFunctionDonor;
use ZEngine\OpCache\ReflectionOpcacheFile;
use ZEngine\OpCache\SharedMemoryException;
use ZEngine\Reflection\FunctionBodySwap;
use ZEngine\Reflection\PendingBodySwap;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\Reflection\ReflectionMethod;

/**
 * The bridge between the file-cache binary-patch pipeline and the runtime
 * hot-swap machinery: takes a (patched) ReflectionOpcacheFile image and applies
 * the changed compiled BODIES to the functions and classes ALREADY LOADED in
 * this process, in place - without re-including the script. A patched binary
 * alone only affects the next include (BinaryCacheFile::refresh()); this class
 * closes the loop for code the process is already running.
 *
 * prepare() diffs the image against the live executor tables and is read-only;
 * apply() drives the existing runtime machinery for every changed body:
 *
 *  - global functions: FunctionBodySwap in-place swap, the redefine() contract
 *    (entry pointer, name, scope, prototype and declaration flags preserved;
 *    the body and its body-level flags follow the image);
 *  - methods: the same swap against the live class entry, ClassDelta-style -
 *    changed bodies only, propagating to subclasses that share the entry;
 *  - opcache-shared (ZEND_ACC_IMMUTABLE) live entries are first copied out of
 *    shared memory through the documented copy-out paths, with every refusal
 *    of that machinery surfacing loudly (docs/hot-swap.md support matrix).
 *
 * Scope: bodies of named global functions and of methods the live class itself
 * declares. Entries only the image knows (script never included here, methods
 * added only in the image) are REPORTED as not loaded, never invented at
 * runtime; the script's main op_array and class-level data (constants,
 * property defaults, attributes) are out of scope - see docs/opcache-binary.md.
 *
 * apply() is ordered (functions before classes, alphabetically within each
 * group), staged (every swap can roll back until all succeeded) and loud: it
 * either works or throws, returning a CacheImageSyncReport of what happened.
 * Re-applying the same sync is refused; re-preparing against the same image
 * diffs as all-unchanged, so the bridge is idempotent per image state.
 *
 * Seam for issue #121 (publishing a patched image into opcache shared memory):
 * prepare() is application-agnostic - the SHM publisher consumes the same
 * prepared diff (getChangedFunctions()/getChangedMethods() plus the image
 * handle) and replaces only the apply() target, per-process tables today, ZCSG
 * tomorrow.
 */
final class CacheImageSync
{
    /** @var array<string, ReflectionFunction> Lc name => live entry to swap */
    private array $changedFunctionEntries = [];

    /** @var array<string, ReflectionFunction> Lc name => image donor function */
    private array $changedFunctionImages = [];

    /** @var array<string, bool> Lc name => live entry was opcache-shared at prepare time */
    private array $functionWasShared = [];

    /** @var array<string, ReflectionClass> Lc class => live class with changed methods */
    private array $changedClassEntries = [];

    /** @var array<string, array<string, ReflectionMethod>> Lc class => lc method => image method */
    private array $changedMethodImages = [];

    /** @var array<string, bool> Lc class => live class was opcache-shared at prepare time */
    private array $classWasShared = [];

    /** @var list<\ReflectionException> Refusals the diff detected; apply() throws the first */
    private array $refusals = [];

    /** @var list<string> */
    private array $unchangedFunctions = [];

    /** @var list<string> */
    private array $unchangedMethods = [];

    /** @var list<string> */
    private array $notLoadedFunctions = [];

    /** @var list<string> */
    private array $notLoadedClasses = [];

    /** @var list<string> */
    private array $notLoadedMethods = [];

    private bool $isApplied = false;

    /**
     * Materialized donors pinned for the lifetime of this sync: the swapped-in bodies
     * execute out of the blocks these own (see ImageFunctionDonor and the retained
     * $image, whose relocated buffer the bodies keep referencing)
     *
     * @var list<ImageFunctionDonor>
     */
    // @phpstan-ignore property.onlyWritten (pure lifetime retention)
    private array $materializedDonors = [];

    private function __construct(private readonly ReflectionOpcacheFile $image) {}

    /**
     * Diffs a relocated cache image against the live process (read-only)
     *
     * The equality basis is ImageFunctionDonor::bodiesEqual(): body metrics,
     * canonicalized opcodes, literal and static-default values. Refusals the diff
     * detects (an image entry colliding with an internal function/class, changed
     * methods of an enum/interface/trait) are recorded and thrown by apply() -
     * preparing stays side-effect free so the plan can be introspected first.
     */
    public static function prepare(ReflectionOpcacheFile $image): self
    {
        $sync = new self($image);
        $sync->diffFunctions();
        $sync->diffClasses();

        return $sync;
    }

    /**
     * @return list<string> Lowercased names of global functions whose body will be swapped
     */
    public function getChangedFunctions(): array
    {
        return array_keys($this->changedFunctionEntries);
    }

    /**
     * @return array<string, list<string>> Lc class name => lc method names whose body will be swapped
     */
    public function getChangedMethods(): array
    {
        return array_map(array_keys(...), $this->changedMethodImages);
    }

    /**
     * Human-readable reasons apply() will refuse this plan with, in throw order
     *
     * Empty when the plan is applicable. The first reason is what apply() throws
     * (as its typed exception); listing them here keeps the refusal introspectable
     * before anything is attempted.
     *
     * @return list<string>
     */
    public function getRefusalReasons(): array
    {
        return array_map(
            static fn(\ReflectionException $refusal): string => $refusal->getMessage(),
            $this->refusals,
        );
    }

    /**
     * Checks if applying this sync would perform no operation and refuse nothing
     */
    public function isEmpty(): bool
    {
        return $this->changedFunctionEntries === []
            && $this->changedMethodImages    === []
            && $this->refusals               === [];
    }

    /**
     * Applies every changed body to the live process, atomically for the batch
     *
     * Order of operations: refusal validation first (nothing is touched when the plan
     * contains a refused entry), then the copy-out of every opcache-shared target,
     * then all body swaps - functions before classes, alphabetically within each
     * group - staged so that a failing swap rolls every already-staged one back.
     * A completed copy-out is NOT undone by that rollback: it is behavior-preserving
     * on its own (the writable copy publishes the same bodies) and the documented
     * copy-out caveats of docs/hot-swap.md apply from that moment on.
     *
     * @throws HotSwapException      When the plan contains a refused entry, this sync was
     *                               already applied, or a swap failed and was rolled back
     * @throws SharedMemoryException When an opcache-shared target cannot be copied out of
     *                               shared memory (preloaded class, unsupported class shape)
     */
    public function apply(): CacheImageSyncReport
    {
        if ($this->isApplied) {
            throw HotSwapException::imageAlreadyApplied($this->image->getFileName());
        }
        if (Core::isShutdown()) {
            throw HotSwapException::shutdown();
        }
        if ($this->refusals !== []) {
            throw $this->refusals[0];
        }

        // Copy-out pass: after this, every target entry is writable per-process memory.
        // SharedMemoryException from here aborts the apply before any body changed.
        foreach ($this->changedFunctionEntries as $liveFunction) {
            $liveFunction->copyEntryOutOfSharedMemory();
        }
        foreach ($this->changedClassEntries as $liveClass) {
            $liveClass->copyOutOfSharedMemory();
        }

        // Materialization pass: allocation and normalization only, nothing published
        $functionDonors = [];
        foreach ($this->changedFunctionImages as $functionName => $imageFunction) {
            $functionDonors[$functionName] = ImageFunctionDonor::materialize($imageFunction);
        }
        $methodDonors = [];
        foreach ($this->changedMethodImages as $classKey => $imageMethods) {
            foreach ($imageMethods as $methodName => $imageMethod) {
                $methodDonors[$classKey][$methodName] = ImageFunctionDonor::materialize($imageMethod);
            }
        }

        // Staging pass: every entry dispatches the new body once its swap is staged,
        // and any failure rolls all staged entries back to their previous bodies
        /** @var list<PendingBodySwap> $pendingSwaps */
        $pendingSwaps = [];
        try {
            foreach ($functionDonors as $functionName => $donor) {
                $entryFunction  = $this->changedFunctionEntries[$functionName];
                $pendingSwaps[] = FunctionBodySwap::swapUserFunctionBody(
                    $entryFunction,
                    $donor->getDonor(),
                    // The entry keeps its declaration identity; only the body travels
                    preserveDeclaration: true,
                    // The image defaults table is pinned by the image buffer, not donor-owned
                    duplicateStatics: false,
                    // A shared-memory previous body is immortal and must not be freed
                    destroyPrevious: !$this->functionWasShared[$functionName],
                    publishedShares: FunctionBodySwap::countPublishedShares($entryFunction),
                );
            }
            foreach ($methodDonors as $classKey => $donors) {
                $liveClass   = $this->changedClassEntries[$classKey];
                $methodTable = $liveClass->getMethodTable();
                foreach ($donors as $methodName => $donor) {
                    // Re-resolved AFTER the copy-out pass: the published entry of a
                    // copied-out class is the writable duplicate, not the SHM original
                    $methodValue = $methodTable->find($methodName);
                    if ($methodValue === null) {
                        throw SharedMemoryException::methodMissingAfterCopyOut((string) $liveClass->getName(), $methodName);
                    }
                    $entryMethod    = ReflectionMethod::fromRawEntry($methodValue->getRawFunction());
                    $pendingSwaps[] = FunctionBodySwap::swapUserFunctionBody(
                        $entryMethod,
                        $donor->getDonor(),
                        preserveDeclaration: true,
                        duplicateStatics: false,
                        destroyPrevious: !$this->classWasShared[$classKey],
                        publishedShares: FunctionBodySwap::countPublishedShares($entryMethod),
                    );
                }
            }
        } catch (\Throwable $error) {
            foreach (array_reverse($pendingSwaps) as $pending) {
                $pending->rollback();
            }
            if ($error instanceof HotSwapException || $error instanceof SharedMemoryException) {
                throw $error;
            }
            throw HotSwapException::imageApplyFailedAndRolledBack($this->image->getFileName(), $error);
        }

        // Commit: from here on nothing can fail - previous bodies are released
        // (shared-memory ones stay allocated by contract)
        foreach ($pendingSwaps as $pending) {
            $pending->commit();
        }
        $this->isApplied = true;
        foreach ($functionDonors as $donor) {
            $this->materializedDonors[] = $donor;
        }
        $appliedMethods = [];
        foreach ($methodDonors as $classKey => $donors) {
            foreach ($donors as $methodName => $donor) {
                $this->materializedDonors[] = $donor;
                $appliedMethods[]           = "{$classKey}::{$methodName}";
            }
        }

        return new CacheImageSyncReport(
            $this->image->getFileName(),
            array_keys($functionDonors),
            $appliedMethods,
            $this->unchangedFunctions,
            $this->unchangedMethods,
            $this->notLoadedFunctions,
            $this->notLoadedClasses,
            $this->notLoadedMethods,
        );
    }

    /**
     * Diffs every image function against the live function table
     */
    private function diffFunctions(): void
    {
        $liveFunctionTable = Core::$executor->functionTable;
        $imageFunctions    = $this->image->getFunctions();
        ksort($imageFunctions);
        foreach ($imageFunctions as $functionName => $imageFunction) {
            $liveValue = $liveFunctionTable->find($functionName);
            if ($liveValue === null) {
                $this->notLoadedFunctions[] = $functionName;
                continue;
            }
            $liveFunction = ReflectionFunction::fromCData($liveValue->getRawFunction());
            if (!$liveFunction->isUserDefined()) {
                // An image body cannot replace a native handler, and the two are never
                // "equal" - this is a refusal, not a skip (throw-or-work, never silent)
                $this->refusals[] = HotSwapException::internalFunctionCollision($functionName);
                continue;
            }
            if (ImageFunctionDonor::bodiesEqual($imageFunction, $liveFunction)) {
                $this->unchangedFunctions[] = $functionName;
                continue;
            }
            $this->changedFunctionEntries[$functionName] = $liveFunction;
            $this->changedFunctionImages[$functionName]  = $imageFunction;
            $this->functionWasShared[$functionName]      = $liveFunction->isImmutable();
        }
    }

    /**
     * Diffs every method an image class declares against the live class
     */
    private function diffClasses(): void
    {
        $liveClassTable = Core::$executor->classTable;
        $imageClasses   = $this->image->getClasses();
        ksort($imageClasses);
        foreach ($imageClasses as $classKey => $imageClass) {
            $liveValue = $liveClassTable->find($classKey);
            if ($liveValue === null) {
                $this->notLoadedClasses[] = $classKey;
                continue;
            }
            $liveClass = ReflectionClass::fromCData($liveValue->getRawClass());
            if (!$liveClass->isUserDefined()) {
                $this->refusals[] = HotSwapException::internalClass((string) $liveClass->getName());
                continue;
            }

            $changedMethods = $this->diffClassMethods($classKey, $imageClass, $liveClass);
            if ($changedMethods === []) {
                continue;
            }
            // Refusals gate MUTATION: an unchanged enum/interface/trait in the image is
            // simply not an operation, only changed bodies of one are refused
            $specialMask = Core::ZEND_ACC_INTERFACE | Core::ZEND_ACC_TRAIT | Core::ZEND_ACC_ENUM;
            if ((($liveClass->getFlags() | $imageClass->getFlags()) & $specialMask) !== 0) {
                $this->refusals[] = HotSwapException::unsupportedKind((string) $liveClass->getName());
                continue;
            }
            if (($liveClass->getFlags() & Core::ZEND_ACC_LINKED) === 0) {
                $this->refusals[] = HotSwapException::notLinked((string) $liveClass->getName());
                continue;
            }
            $this->changedClassEntries[$classKey] = $liveClass;
            $this->changedMethodImages[$classKey] = $changedMethods;
            $this->classWasShared[$classKey]      = $liveClass->isImmutable();
        }
    }

    /**
     * Diffs the methods one image class declares against the live class entry
     *
     * @return array<string, ReflectionMethod> Lc method name => image method with a changed body
     */
    private function diffClassMethods(string $classKey, ReflectionClass $imageClass, ReflectionClass $liveClass): array
    {
        // A method-less class stores an UNINITIALIZED method table in the image
        // (no bucket array to iterate) - and declares nothing to diff anyway
        if (count($imageClass->getMethodTable()) === 0) {
            return [];
        }
        $changedMethods  = [];
        $liveMethodTable = $liveClass->getMethodTable();
        $liveAddress     = $liveClass->getAddress();
        $imageMethods    = $imageClass->getDeclaredMethods();
        ksort($imageMethods);
        foreach ($imageMethods as $methodName => $imageMethod) {
            $liveMethodValue = $liveMethodTable->find($methodName);
            if ($liveMethodValue === null) {
                // Declared only in the image (a patch added it): out of the bridge's
                // scope - the next include of the patched binary publishes it
                $this->notLoadedMethods[] = "{$classKey}::{$methodName}";
                continue;
            }
            $liveMethod = ReflectionMethod::fromRawEntry($liveMethodValue->getRawFunction());
            if (!$liveMethod->isUserDefined()) {
                $this->refusals[] = HotSwapException::internalMethodCollision($classKey, $methodName);
                continue;
            }
            $liveScope = $liveMethod->getCommonPointer()->scope;
            if ($liveScope === null || Core::addressOf($liveScope) !== $liveAddress) {
                // The live table publishes an INHERITED entry under this name: swapping
                // it would mutate the ancestor's method for every subclass. The image
                // method is an override that only the next include can add.
                $this->notLoadedMethods[] = "{$classKey}::{$methodName}";
                continue;
            }
            if (ImageFunctionDonor::bodiesEqual($imageMethod, $liveMethod)) {
                $this->unchangedMethods[] = "{$classKey}::{$methodName}";
                continue;
            }
            $changedMethods[$methodName] = $imageMethod;
        }

        return $changedMethods;
    }
}
