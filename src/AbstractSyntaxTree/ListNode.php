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

use ZEngine\Core;

/**
 * List node is used where the number of children is determined dynamically.
 *
 * It is identical to ordinary AST nodes, but contains an additional children count.
 *
 * typedef struct _zend_ast_list {
 *   zend_ast_kind kind;
 *   zend_ast_attr attr;
 *   zend_uint lineno;
 *   zend_uint children;
 *   zend_ast *child[1];
 * } zend_ast_list;
 */
class ListNode extends Node
{
    /**
     * Creates a list of given type
     *
     * @param int $kind
     */
    public function __construct(int $kind)
    {
        if (!NodeKind::isList($kind)) {
            $kindName = NodeKind::name($kind);
            throw new \InvalidArgumentException('Given AST type ' . $kindName . ' does not belong to list type');
        }

        $ast  = Core::call('zend_ast_create_list_0', $kind);
        $list = Core::cast('zend_ast_list *', $ast);

        $this->node = $list;
    }

    /**
     * Returns children node count
     */
    public function getChildrenCount(): int
    {
        // List stores the number of nodes in separate field
        return $this->node->children;
    }

    /**
     * Adds one or several nodes to the list
     *
     * @param NodeInterface ...$nodes List of nodes to add
     */
    public function append(NodeInterface ...$nodes): void
    {
        // This variable can be redeclared (if list will grow during node addition)
        $selfNode = Core::cast('zend_ast *', $this->node);
        foreach ($nodes as $node) {
            $astNode  = Core::cast('zend_ast *', $node->node);
            $selfNode = Core::call('zend_ast_list_add', $selfNode, $astNode);
        }

        $this->node = Core::cast('zend_ast_list *', $selfNode);
    }
}
