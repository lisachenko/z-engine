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

namespace ZEngine\AbstractSyntaxTree;

use FFI\CData;
use ZEngine\Core;

/**
 * Owns the memory behind a detached AST produced by Compiler::parseString()
 *
 * The parser allocates every node inside a z-engine supplied arena and stores refcounted
 * payloads (strings, doc comments, constant values) inside the nodes. This object keeps both
 * alive for as long as any Node wrapper references the tree, and releases them exactly once:
 * zend_ast_destroy() drops the payload references, then the arena buffer itself is freed.
 *
 * Note that additional arena blocks chained by the engine for very large inputs are allocated
 * by the engine itself and are deliberately not freed here (z-engine never frees memory that
 * it did not allocate); they are reclaimed by the memory manager at request end.
 */
final class AstOwnership
{
    private ?CData $rootAst;

    private ?CData $arenaBuffer;
    /**
     * @param \FFI\CData|null $rootAst
     * @param \FFI\CData|null $arenaBuffer
     */

    public function __construct(?object $rootAst, ?object $arenaBuffer)
    {
        $this->rootAst     = $rootAst;
        $this->arenaBuffer = $arenaBuffer;
    }

    /**
     * Checks if the underlying tree has been released already
     */
    public function isReleased(): bool
    {
        return $this->rootAst === null && $this->arenaBuffer === null;
    }

    /**
     * Releases the tree payloads and the arena buffer (idempotent)
     */
    public function release(): void
    {
        if ($this->rootAst !== null) {
            Core::call('zend_ast_destroy', Core::cast('zend_ast *', $this->rootAst));
            $this->rootAst = null;
        }
        if ($this->arenaBuffer !== null) {
            Core::free($this->arenaBuffer);
            $this->arenaBuffer = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
