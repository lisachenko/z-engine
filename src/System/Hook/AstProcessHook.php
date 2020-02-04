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
class AstProcessHook extends AbstractHook
{
    protected const HOOK_FIELD = 'zend_ast_process';

    /**
     * Instance of top-level AST node
     */
    protected CData $ast;

    /**
     * typedef void (*zend_ast_process_t)(zend_ast *ast);
     *
     * @inheritDoc
     */
    public function handle(...$rawArguments): void
    {
        [$this->ast] = $rawArguments;

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
    public function proceed()
    {
        if (!$this->hasOriginalHandler()) {
            throw new \LogicException('Original handler is not available');
        }
        ($this->originalHandler)($this->ast);
    }
}
