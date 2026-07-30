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

namespace ZEngine\Type;

/**
 * Ownership tracking for wrappers around engine memory
 *
 * Two orthogonal ownership bits describe what a wrapper must clean up:
 *
 *  - ownsContainer: z-engine allocated the container CData (eg a 16-byte zval box) and
 *    must FFI-free it. Engine-owned containers (hashtable buckets, frame slots) stay false.
 *  - ownsReference: this wrapper holds exactly one reference on the refcounted payload and
 *    must release it exactly once through the engine primitives.
 *
 * The engine refcount stays the single source of truth: because every owning wrapper holds
 * its own reference, aliasing two wrappers over the same pointer can never double-free.
 * Borrowed wrappers (both bits false) treat release() as a no-op and stay usable.
 */
trait ReleasableTrait
{
    private bool $ownsContainer = false;

    private bool $ownsReference = false;

    private bool $released = false;

    /**
     * Checks if this wrapper already released the memory it owned
     */
    public function isReleased(): bool
    {
        return $this->released;
    }

    /**
     * Releases everything this wrapper owns (idempotent)
     *
     * Borrowed wrappers own nothing, so for them this is a no-op and the wrapper stays usable.
     * After an owning wrapper is released, any further access to the underlying memory throws.
     */
    final public function release(): void
    {
        if ($this->released || (!$this->ownsReference && !$this->ownsContainer)) {
            return;
        }
        // Flip the flag first so that release stays idempotent even if the cleanup throws
        $this->released = true;

        $ownsReference       = $this->ownsReference;
        $ownsContainer       = $this->ownsContainer;
        $this->ownsReference = false;
        $this->ownsContainer = false;

        $this->doRelease($ownsReference, $ownsContainer);
    }

    /**
     * Transfers ownership of the payload reference to an engine sink and returns the wrapper
     *
     * Use this when a pointer is stored into an engine structure that will release it later
     * (eg a class entry field destroyed by destroy_zend_class): the engine takes over the
     * reference this wrapper held, so the wrapper must not release it again.
     */
    final public function transferReferenceOwnership(): static
    {
        $this->ownsReference = false;

        return $this;
    }

    public function __destruct()
    {
        $this->release();
    }

    /**
     * Performs the concrete cleanup for the owning wrapper
     */
    abstract protected function doRelease(bool $ownsReference, bool $ownsContainer): void;

    /**
     * Throws when the wrapped memory has been released already
     */
    private function assertNotReleased(): void
    {
        if ($this->released) {
            throw new \LogicException('This wrapper has been released, the underlying memory is no longer valid');
        }
    }
}
