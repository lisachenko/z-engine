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
use ZEngine\Core;
use ZEngine\Hook\AbstractHook;

/**
 * Receiving hook for processing an AST
 *
 * The callback runs while CG(in_compilation) is set, and in that state the engine promotes
 * every internally-raised exception to an immediate fatal error before any catch runs -
 * including thrown-and-caught ones inside library code, such as Core::cast()'s array-decay
 * probe, which nearly every AST accessor crosses. Consumers therefore bracket their tree
 * work with Compiler::processInCompilationMode(), which restores the previous mode in a
 * finally block:
 *
 *     Core::setASTProcessHandler(function (AstProcessHook $hook): void {
 *         Core::$compiler->processInCompilationMode(false, fn () => rewrite($hook->getAST()));
 *         if ($hook->hasOriginalHandler()) {
 *             $hook->proceed();
 *         }
 *     });
 */
final class AstProcessHook extends AbstractHook
{
    protected const string HOOK_FIELD = 'zend_ast_process';

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
     * Returns the name of the compilation unit this hook fires for
     *
     * Safe to call inside the callback: the underlying accessor reads the zend_string
     * directly and never crosses a throwing code path (see Compiler::getFileName()).
     */
    public function getFileName(): string
    {
        return Core::$compiler->getFileName();
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
