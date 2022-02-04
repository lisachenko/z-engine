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
use ZEngine\Constants\_zend_ast_kind;
use ZEngine\Core;

/**
 * Node factory is used to create an instance of concrete Node class from raw CData `zend_ast` entry
 */
class NodeFactory
{
    /**
     * Factory method that creates an instance of PHP node from C representation
     *
     * @param CData $node
     *
     * @return NodeInterface
     */
    public static function fromCData(CData $node): NodeInterface
    {
        $kind = $node->kind;
        switch (true) {
            // There are special node types ZVAL, CONSTANT, ZNODE
            case $kind === NodeKind::AST_ZVAL:
                $node = Core::cast('zend_ast_zval *', $node);
                return ValueNode::fromCData($node);
            case $kind === NodeKind::AST_CONSTANT:
            case $kind === NodeKind::AST_ZNODE:
                throw new \RuntimeException('Not yet supported: ' . NodeKind::name($kind));
            case NodeKind::isSpecial($kind):
                $node = Core::cast('zend_ast_decl *', $node);
                return DeclarationNode::fromCData($node);
            case NodeKind::isList($kind):
                $node = Core::cast('zend_ast_list *', $node);
                return ListNode::fromCData($node);
            default:
                return Node::fromCData($node);
        }
    }
}
