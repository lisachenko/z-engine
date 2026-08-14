<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2020, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\System\Hook;

use FFI\CData;
use ZEngine\AbstractSyntaxTree\NodeFactory;
use ZEngine\AbstractSyntaxTree\NodeInterface;
use ZEngine\Hook\AbstractHook;

/**
 * Receiving hook for processing an AST
 */
final class AstProcessHook extends AbstractHook
{
    protected const HOOK_FIELD = 'zend_ast_process';

    /**
     * Instance of top-level AST node
     *
     * Kept as a raw handle: the AST wrappers (NodeFactory/Node) own the zend_ast struct
     * and still take CData, so narrowing to a stub view here would only be undone again.
     */
    protected CData $ast;

    /**
     * typedef void (*zend_ast_process_t)(zend_ast *ast);
     *
     * @inheritDoc
     */
    #[\Override]
    public function handle(...$rawArguments): void
    {
        /** @var CData $ast Narrowed at the engine callback boundary */
        [$ast]     = $rawArguments;
        $this->ast = $ast;

        ($this->userHandler)($this);
    }

    /**
     * Returns a top-level node element
     */
    public function getAST(): NodeInterface
    {
        return NodeFactory::fromCData($this->ast);
    }

    /**
     * Proceeds with default callback
     */
    public function proceed(): void
    {
        $originalHandler = $this->getOriginalCallable();

        ($originalHandler)($this->ast);
    }
}
