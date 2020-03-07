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


/**
 * General AST node interface
 */
interface NodeInterface
{
    /**
     * Returns the constant indicating the type of the AST node
     *
     * @see NodeKind class constants
     */
    public function getKind(): int;

    /**
     * Returns node's kind-specific flags
     */
    public function getAttributes(): int;

    /**
     * Changes node attributes
     */
    public function setAttributes(int $newAttributes): int;

    /**
     * Returns the start line number of the node
     */
    public function getLine(): int;

    /**
     * Changes the node line
     */
    public function setLine(int $newLine): void;

    /**
     * Returns the number of children for this node
     */
    public function getChildrenCount(): int;

    /**
     * Returns children of this node
     *
     * @return NodeInterface[]
     */
    public function getChildren(): array;

    /**
     * Dumps current node in friendly format
     *
     * @param int $indent Level of indentation
     */
    public function dump(int $indent = 0): string;

    /**
     * Replace one child node with another one without checks
     *
     * @param int           $index Child node index
     * @param NodeInterface $node  New node to use
     */
    public function replaceChild(int $index, NodeInterface $node): void;

    /**
     * Return concrete child by index (can be empty)
     *
     * @param int $index Index of child node
     */
    public function getChild(int $index): ?NodeInterface;

    /**
     * Removes a child node from the tree and returns the removed node.
     *
     * @param int $index Index of the node to remove
     */
    public function removeChild(int $index): NodeInterface;
}
