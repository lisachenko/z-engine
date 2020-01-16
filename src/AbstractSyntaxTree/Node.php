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
use ReflectionClass;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionMethod;
use function count;
use function strpos;

/**
 * General node class that can contain several children nodes
 *
 * typedef struct _zend_ast {
 *   zend_ast_kind kind;
 *   zend_ast_attr attr;
 *   zend_uint lineno;
 *   struct _zend_ast *child[1];
 * } zend_ast;
 */
class Node implements NodeInterface
{
    protected CData $node;

    /**
     * Creates an instance of Node
     *
     * @param int       $kind       Node kind
     * @param int       $attributes Node attributes (like modifier, options, etc)
     * @param Node|null ...$nodes   List of nested nodes (if required)
     */
    public function __construct(int $kind, int $attributes, ?Node ...$nodes)
    {
        $nodeCount     = count($nodes);
        $expectedCount = NodeKind::childrenCount($kind);
        if ($expectedCount !== $nodeCount || $nodeCount > 4) {
            $kindName = NodeKind::name($kind);
            $message  = 'Given AST type ' . $kindName . ' expects exactly ' . $expectedCount . ' argument(s).';
            throw new \InvalidArgumentException($message);
        }
        $funcName  = "zend_ast_create_{$nodeCount}";
        $arguments = [];
        foreach ($nodes as $index => $node) {
            if ($node === null) {
                $arguments[$index] = null;
            } else {
                $arguments[$index] = Core::cast('zend_ast *', $node->node);
            }
        }
        $node = Core::call($funcName, $kind, ...$arguments);
        $this->node = $node;
        $this->setAttributes($attributes);
    }

    /**
     * Node static constructor.
     */
    public static function fromCData(CData $node): Node
    {
        /** @var self $instance */
        $instance = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();

        $instance->node = $node;

        return $instance;
    }

    /**
     * Returns the constant indicating the type of the AST node
     *
     * @see NodeKind class constants
     */
    final public function getKind(): int
    {
        return $this->node->kind;
    }

    /**
     * Returns node's kind-specific flags
     */
    final public function getAttributes(): int
    {
        return $this->node->attr;
    }

    /**
     * Changes node attributes
     */
    final public function setAttributes(int $newAttributes): int
    {
        return $this->node->attr = $newAttributes;
    }

    /**
     * Returns the start line number of the node
     */
    public function getLine(): int
    {
        return $this->node->lineno;
    }

    /**
     * Changes the node line
     */
    public function setLine(int $newLine): void
    {
        $this->node->lineno = $newLine;
    }

    /**
     * Returns the number of children for this node
     */
    public function getChildrenCount(): int
    {
        return NodeKind::childrenCount($this->node->kind);
    }

    /**
     * Returns children of this node
     *
     * @return NodeInterface[]
     */
    final public function getChildren(): array
    {
        $totalChildren = $this->getChildrenCount();
        if ($totalChildren === 0) {
            return [];
        }

        $children     = [];
        $castChildren = Core::cast('zend_ast **', $this->node->child);
        for ($index = 0; $index < $totalChildren; $index++) {
            if ($castChildren[$index] !== null) {
                $children[$index] = NodeFactory::fromCData($castChildren[$index]);
            } else {
                $children[$index] = null;
            }
        }

        return $children;
    }

    /**
     * Return concrete child by index (can be empty)
     *
     * @param int $index Index of child node
     */
    final public function getChild(int $index): ?NodeInterface
    {
        $totalChildren = $this->getChildrenCount();
        if ($index >= $totalChildren) {
            throw new \OutOfBoundsException('Child index is out of range, there are ' . $totalChildren . ' children.');
        }
        $castChildren = Core::cast('zend_ast **', $this->node->child);
        if ($castChildren[$index] === null) {
            return null;
        }

        return NodeFactory::fromCData($castChildren[$index]);
    }

    /**
     * Replace one child node with another one without checks
     *
     * @param int       $index Child node index
     * @param Node|null $node  New node to use or null to unset child
     */
    public function replaceChild(int $index, ?Node $node): void
    {
        $totalChildren = $this->getChildrenCount();
        if ($index >= $totalChildren) {
            throw new \OutOfBoundsException('Child index is out of range, there are ' . $totalChildren . ' children.');
        }
        $castChildren = Core::cast('zend_ast **', $this->node->child);
        $castChildren[$index] = $node ? Core::cast('zend_ast *', $node->node) : null;
    }

    /**
     * This method is used to prevent segmentation faults when dumping CData
     */
    final public function __debugInfo(): array
    {
        $result  = [];
        $methods = (new ReflectionClass(static::class))->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            $methodName = $method->getName();
            if ((strpos($methodName, 'get') === 0) && $method->getNumberOfRequiredParameters() === 0) {
                $name = lcfirst(substr($methodName, 3));
                $result[$name] = $this->$methodName();
            }
        }

        return $result;
    }

    /**
     * Dumps current node in friendly format
     *
     * @param int $indent Level of indentation
     */
    final public function dump(int $indent = 0): string
    {
        $content = sprintf('%4d', $this->getLine()) . ': ';
        $content .= $this->dumpThis($indent) . "\n";

        $childrenCount = $this->getChildrenCount();
        if ($childrenCount > 0) {
            $children = $this->getChildren();
            $content .= $this->dumpChildren($indent, ...$children);
        }

        return $content;
    }

    /**
     * Dumps current node itself (without children)
     */
    protected function dumpThis(int $indent = 0): string
    {
        $line = str_repeat(' ', 2 * $indent);
        $line .= NodeKind::name($this->getKind());

        $attributes = $this->getAttributes();
        if ($attributes !== 0) {
            $line .= sprintf(" attrs(%04x)", $attributes);
        }

        return $line;
    }

    /**
     * Helper method to dump children nodes
     *
     * @param int                $indent   Current level of indentation
     * @param NodeInterface|null ...$nodes List of children nodes (can contain null values)
     */
    private function dumpChildren(int $indent = 0, ?NodeInterface ...$nodes): string
    {
        $content = '';
        foreach ($nodes as $index => $node) {
            if ($node === null) {
                continue;
            }
            $content .= $node->dump($indent + 1);
        }

        return $content;
    }
}
