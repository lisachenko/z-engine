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

namespace ZEngine\AbstractSyntaxTree;

use FFI\CData;
use ZEngine\Core;
use ZEngine\Generated\zend_ast;

/**
 * Node factory is used to create an instance of concrete Node class from raw CData `zend_ast` entry
 */
class NodeFactory
{
    /**
     * Factory method that creates an instance of PHP node from C representation
     *
     * @param CData|zend_ast    $node Pointer to the structure
     * @param AstOwnership|null $owner Ownership handle that must stay alive while the node is used
     *
     * @return NodeInterface
     */
    public static function fromCData(object $node, ?AstOwnership $owner = null): NodeInterface
    {
        /** @var zend_ast $node Narrowed to the stub view at the owning boundary */
        $kind = $node->kind;
        switch (true) {
            // There are special node types ZVAL, CONSTANT, ZNODE
            case $kind === NodeKind::AST_ZVAL:
                $node = Core::cast('zend_ast_zval *', $node);
                return ValueNode::fromCData($node, $owner);
            case $kind === NodeKind::AST_CONSTANT:
            case $kind === NodeKind::AST_ZNODE:
                throw new \RuntimeException('Not yet supported: ' . NodeKind::name($kind));
            case NodeKind::isSpecial($kind):
                $node = Core::cast('zend_ast_decl *', $node);
                return DeclarationNode::fromCData($node, $owner);
            case NodeKind::isList($kind):
                $node = Core::cast('zend_ast_list *', $node);
                return ListNode::fromCData($node, $owner);
            default:
                return Node::fromCData($node, $owner);
        }
    }
}
