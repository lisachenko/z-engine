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

use FFI\CData;
use ZEngine\Core;

/**
 * Handle of one staged in-place function body swap (see FunctionBodySwap)
 *
 * The entry already executes the new body; the previous body is kept alive in a
 * byte-exact snapshot until the caller decides:
 *
 *  - commit(): destroys the previous body with engine semantics (or keeps it
 *    allocated for opcache shared-memory bodies, which are never freed);
 *  - rollback(): restores the previous struct wholesale and returns the resources
 *    the swap took from the donor (body references, fresh run-time cache, minted
 *    statics duplicate). Only legal while the donor body is still alive.
 *
 * Exactly one of the two must be called; the handle is single-use.
 */
final class PendingBodySwap
{
    private bool $isResolved = false;

    /**
     * @param FunctionLikeInterface $entry             Swapped entry (published function/method)
     * @param CData              $previousBody         zend_function snapshot of the previous body
     * @param int                $entryAddress         Numeric identity of the entry
     * @param int                $publishedShares      Bucket shares held on both the previous and the new body
     * @param bool               $destroyPrevious      False for shared-memory previous bodies (never freed)
     */
    public function __construct(
        private FunctionLikeInterface $entry,
        private CData $previousBody,
        private int $entryAddress,
        private int $publishedShares,
        private bool $destroyPrevious,
    ) {}

    /**
     * Finalizes the swap: the previous body is destroyed (unless it is immortal SHM)
     */
    public function commit(): void
    {
        $this->assertUnresolved();
        $this->isResolved = true;
        if ($this->destroyPrevious) {
            FunctionBodySwap::destroyPreviousBody($this->previousBody, $this->entryAddress, $this->publishedShares);
        }
    }

    /**
     * Reverts the swap: the entry is restored to the previous body byte-exact
     *
     * Must run while the donor body is still alive (the staged apply/rollback window
     * of ClassDelta guarantees that): the references taken on the new body are
     * returned to it before the restore.
     */
    public function rollback(): void
    {
        $this->assertUnresolved();
        $this->isResolved = true;
        FunctionBodySwap::releaseSwappedInBody($this->entry, $this->publishedShares);
        Core::memcpy($this->entry->getEntryPointer(), Core::addr($this->previousBody), Core::sizeof($this->previousBody));
    }

    private function assertUnresolved(): void
    {
        if ($this->isResolved) {
            throw new \LogicException('This body swap has already been committed or rolled back');
        }
    }
}
