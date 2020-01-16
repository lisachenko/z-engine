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
use ZEngine\Type\StringEntry;

/**
 * DeclarationNode is used for class and function declarations
 *
 * typedef struct _zend_ast_decl {
 *   zend_ast_kind kind;
 *   zend_ast_attr attr; // Unused - for structure compatibility
 *   uint32_t start_lineno;
 *   uint32_t end_lineno;
 *   uint32_t flags;
 *   unsigned char *lex_pos;
 *   zend_string *doc_comment;
 *   zend_string *name;
 *   zend_ast *child[4];
 * } zend_ast_decl;
 */
class DeclarationNode extends Node
{
    /**
     * Creates a declaration of given type
     */
    public function __construct(
        int $kind,
        int $flags,
        int $startLine,
        int $endLine,
        string $docComment,
        string $name,
        ?NodeInterface ...$childrenNodes
    ) {
        if (!NodeKind::isSpecial($kind)) {
            $kindName = NodeKind::name($kind);
            throw new \InvalidArgumentException('Given AST type ' . $kindName . ' does not belong to declaration');
        }

        if (count($childrenNodes) > 4) {
            throw new \InvalidArgumentException('Declaration node can contain only up to 4 children nodes');
        }

        // Fill exactly 4 nodes with default null values
        $childrenNodes = $childrenNodes + array_fill(0, 4, null);

        // ZEND_API zend_ast *zend_ast_create_decl(
        //    zend_ast_kind kind, uint32_t flags, uint32_t start_lineno, zend_string *doc_comment,
        //    zend_string *name, zend_ast *child0, zend_ast *child1, zend_ast *child2, zend_ast *child3
        //);
        $ast = Core::call(
            'zend_ast_create_decl', $kind, $flags, $startLine, $endLine, $docComment,
            $name, ...$childrenNodes
        );

        $declaration = Core::cast('zend_ast_decl *', $ast);

        $this->node = $declaration;
    }

    /**
     * As declaration node spans several lines, just return start line instead
     */
    public function getLine(): int
    {
        return $this->node->start_lineno;
    }

    /**
     * Changes the node line (actually, it's a start line)
     */
    public function setLine(int $newLine): void
    {
        $this->node->start_lineno = $newLine;
    }

    /**
     * Returns the end line
     */
    public function getEndLine(): int
    {
        return $this->node->end_lineno;
    }

    /**
     * Changes the node end line
     */
    public function setEndLine(int $newLine): void
    {
        $this->node->end_lineno = $newLine;
    }

    /**
     * Returns node flags
     */
    public function getFlags(): int
    {
        return $this->node->flags;
    }

    /**
     * Changes node flags
     */
    public function setFlags(int $newFlags): void
    {
        $this->node->flags = $newFlags;
    }

    /**
     * Returns node flags
     */
    public function getLexPosition(): int
    {
        return $this->node->lex_pos[0];
    }

    /**
     * Returns doc comment
     */
    public function getDocComment(): string
    {
        if ($this->node->doc_comment === null) {
            return '';
        }

        // TODO: investigate what to do with string copying
        return StringEntry::fromCData($this->node->doc_comment)->copy()->getStringValue();
    }

    /**
     * Changes the doc comment for this declaration
     */
    public function setDocComment(string $newDocComment): void
    {
        $entry = new StringEntry($newDocComment);

        // TODO: investigate what to do with string copying
        $this->node->doc_comment = $entry->copy()->getRawValue();
    }

    /**
     * Returns the name of entry
     */
    public function getName(): string
    {
        // TODO: investigate what to do with string copying
        return StringEntry::fromCData($this->node->name)->copy()->getStringValue();
    }

    /**
     * Changes the name of this node
     */
    public function setName(string $newName): void
    {
        $entry = new StringEntry($newName);

        // TODO: investigate what to do with string copying
        $this->node->name = $entry->copy()->getRawValue();
    }

    /**
     * @inheritDoc
     */
    public function getChildrenCount(): int
    {
        // Declaration node always contain 4 children nodes.
        return 4;
    }

    /**
     * @inheritDoc
     */
    protected function dumpThis(int $indent = 0): string
    {
        $line = parent::dumpThis($indent);

        $kind = $this->getKind();

        if ($kind !== NodeKind::AST_CLOSURE) {
            $line .= ' ' . $this->getName();
        }

        $flags = $this->getFlags();
        if ($flags !== 0) {
            $line .= sprintf(" flags(%04x)", $flags);
        }

        return $line;
    }
}
