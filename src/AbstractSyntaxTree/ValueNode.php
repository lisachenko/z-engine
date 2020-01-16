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
use ZEngine\Reflection\ReflectionValue;

/**
 * ValueNode stores a zval
 *
 * // Lineno is stored in val.u2.lineno
 * typedef struct _zend_ast_zval {
 *   zend_ast_kind kind;
 *   zend_ast_attr attr;
 *   zval val;
 * } zend_ast_zval;
 *
 * @see zend_ast.h:zend_ast_zval
 */
class ValueNode extends Node
{
    /**
     * Creates an AST node from value
     *
     * @param mixed $value      Any valid value
     * @param int   $attributes Additional attributes
     */
    public function __construct($value, int $attributes = 0)
    {
        // This code is used to extract a Zval for our $value argument and use its internal pointer
        $valueArgument = Core::$executor->getExecutionState()->getArgument(0);
        $rawValue      = $valueArgument->getRawValue();

        $node = Core::call('zend_ast_create_zval_ex', $rawValue, $attributes);
        $node = Core::cast('zend_ast_zval *', $node);

        $this->node = $node;
    }

    /**
     * Returns current node value
     */
    public function getValue(): ReflectionValue
    {
        return ReflectionValue::fromValueEntry($this->node->val);
    }

    /**
     * For ValueNode line is stored in the val.u2.lineno
     *
     * @inheritDoc
     */
    public function getLine(): int
    {
        return $this->getValue()->getExtraValue();
    }

    /**
     * @inheritDoc
     * Value node doesn't have children nodes
     */
    public function getChildrenCount(): int
    {
        return 0;
    }

    /**
     * @inheritDoc
     */
    public function dumpThis(int $indent = 0): string
    {
        $line = parent::dumpThis($indent);

        $line .= ' ';
        $this->getValue()->getNativeValue($value);
        if (is_scalar($value)) {
            $line .= gettype($value) . '(' . var_export($value, true) . ')';
        } else {
            // shouldn't happen
            $line .= gettype($value) . "\n";
        }

        return $line;
    }
}
