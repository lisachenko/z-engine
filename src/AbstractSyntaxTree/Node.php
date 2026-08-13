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

use function count;

use FFI\CData;
use ReflectionClass;

use function strpos;

use ZEngine\Core;
use ZEngine\Reflection\ReflectionMethod;
use ZEngine\Type\StructArray;

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
     * Keeps the arena/tree owner alive for detached trees (null for engine-owned/borrowed nodes)
     */
    protected ?AstOwnership $owner = null;

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
        $node       = Core::call($funcName, $kind, ...$arguments);
        $this->node = $node;
        $this->setAttributes($attributes);
    }

    /**
     * Node static constructor.
     *
     * @param AstOwnership|null $owner Ownership handle that must stay alive while this node is used
     * @param \FFI\CData $node
     */
    public static function fromCData(object $node, ?AstOwnership $owner = null): Node
    {
        /** @var self $instance */
        $instance = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();

        $instance->node  = $node;
        $instance->owner = $owner;

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

        $children = [];
        for ($index = 0; $index < $totalChildren; $index++) {
            $child            = $this->childAt($index);
            $children[$index] = $child !== null ? NodeFactory::fromCData($child, $this->owner) : null;
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
        $child = $this->childAt($index);
        if ($child === null) {
            return null;
        }

        return NodeFactory::fromCData($child, $this->owner);
    }

    /**
     * Replace one child node with another one without checks
     *
     * @param int           $index Child node index
     * @param NodeInterface $node  New node to use
     */
    public function replaceChild(int $index, NodeInterface $node): void
    {
        $rawNode = self::rawNodeOf($node, __FUNCTION__);
        $this->childSlotsFor($index)->storePointer($index, Core::cast('zend_ast *', $rawNode));
    }

    /**
     * Removes a child node from the tree and returns the removed node.
     *
     * @param int $index Index of the node to remove
     */
    public function removeChild(int $index): NodeInterface
    {
        $childSlots = $this->childSlotsFor($index);
        $child      = NodeFactory::fromCData($childSlots[$index], $this->owner);
        $childSlots->storePointer($index, null);

        return $child;
    }

    /**
     * Returns the raw zend_ast pointer of a node handed in through the NodeInterface API
     *
     * The child pointers this class stores are engine zend_ast structures, which only the
     * Node hierarchy owns; the public methods keep the wider NodeInterface parameter type
     * (narrowing it would be a BC break) and this guard turns what used to be a fatal
     * "access to protected property" on a foreign implementation into a normal exception.
     *
     * @return CData zend_ast (or zend_ast_list) pointer of the given node
     */
    protected static function rawNodeOf(NodeInterface $node, string $operation): CData
    {
        if (!$node instanceof self) {
            throw new \InvalidArgumentException(sprintf(
                '%s() accepts only nodes backed by an engine zend_ast (a %s instance), %s given.',
                $operation,
                self::class,
                get_class($node),
            ));
        }

        return $node->node;
    }

    /**
     * Reads one child pointer of this node, or null when that slot is empty
     *
     * The engine stores an absent child as a NULL pointer, a shape the element-typed
     * struct-array view does not model, so the read is narrowed once here.
     *
     * @return CData|null zend_ast pointer of the child
     */
    private function childAt(int $index): ?CData
    {
        /** @var CData|null $child The engine leaves optional children NULL */
        $child = $this->childSlotsFor($index)[$index];

        return $child;
    }

    /**
     * Returns the child-pointer view of this node after refusing an unusable index
     *
     * zend_ast declares its children as the flexible `zend_ast *child[1]` array, so the real
     * bound is the node's runtime child count (ListNode overrides it with the list counter).
     * Routing every accessor through Type\StructArray checks BOTH ends of that range: a
     * NEGATIVE index used to pass the `$index >= $totalChildren` guards untouched and made
     * the three writers read and overwrite engine memory in front of the node itself.
     *
     * @return StructArray<CData>
     */
    private function childSlotsFor(int $index): StructArray
    {
        $totalChildren = $this->getChildrenCount();
        $childSlots    = new StructArray(Core::cast('zend_ast **', $this->node->child), $totalChildren);
        if (!isset($childSlots[$index])) {
            throw new \OutOfBoundsException(
                'Child index ' . $index . ' is out of range, there are ' . $totalChildren . ' children.',
            );
        }

        return $childSlots;
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
                $name          = lcfirst(substr($methodName, 3));
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
            $line .= sprintf(' attrs(%04x)', $attributes);
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
