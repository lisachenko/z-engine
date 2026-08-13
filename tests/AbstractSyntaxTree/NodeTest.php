<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\AbstractSyntaxTree;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ZEngine\Core;

/**
 * Covers the node wrappers over a real parse tree.
 *
 * Every test here drives `Core::$compiler->parseString()`, which mutates the compiler
 * globals (CG(ast), CG(ast_arena), the lexical state), allocates an arena and finally
 * releases the whole tree through AstOwnership - `zend_ast_destroy()` plus a
 * `Core::free()` of the arena buffer. That is engine-state mutation and engine memory
 * management, so the suite lives in the `internal` group and runs process-isolated,
 * exactly like the other tests that drive the compiler (System\CompilerTest) or the
 * parse-string leak scenario (Memory\MemoryLeakScenarioTest).
 *
 * The AstOwnership handle travels with every wrapper produced from the tree, so the
 * tree stays alive as long as any Node in the test is reachable and is released once
 * the last one is collected. Two rules follow from that and are respected below:
 *
 * - the wrappers are never constructed directly (`new Node(...)` calls
 *   `zend_ast_create_*`, which allocates from CG(ast_arena) and is only valid while
 *   the engine is actually parsing);
 * - a node is never left detached from the tree and never linked into it twice - a
 *   detached subtree would leak its payload references, a doubly-linked one would be
 *   destroyed twice. `removeChild()` is therefore always paired with a re-attach, and
 *   `replaceChild()` is only used to permute existing children.
 */
#[Group('internal')]
final class NodeTest extends TestCase
{
    private const FILE_NAME = 'z-engine node test';

    #[RunInSeparateProcess]
    public function testParseStringProducesAStatementListRoot(): void
    {
        $root = Core::$compiler->parseString('$alpha = 42;', self::FILE_NAME);

        $this->assertInstanceOf(ListNode::class, $root);
        $this->assertSame(NodeKind::AST_STMT_LIST, $root->getKind());
        $this->assertSame('AST_STMT_LIST', NodeKind::name($root->getKind()));
        $this->assertSame(1, $root->getChildrenCount());
        $this->assertSame(1, $root->getLine());
    }

    /**
     * A list node reports the children count from its own zend_ast_list header
     * (`children`), not from the kind encoding - a statement-only source therefore
     * yields an empty, but perfectly valid, list.
     */
    #[RunInSeparateProcess]
    public function testEmptyStatementListReportsNoChildren(): void
    {
        $root = Core::$compiler->parseString('// nothing but a comment', self::FILE_NAME);

        $this->assertInstanceOf(ListNode::class, $root);
        $this->assertSame(NodeKind::AST_STMT_LIST, $root->getKind());
        $this->assertSame(0, $root->getChildrenCount());
        $this->assertSame([], $root->getChildren());
    }

    #[RunInSeparateProcess]
    public function testChildrenAreReturnedInSourceOrderWithTheirOwnLines(): void
    {
        $root = Core::$compiler->parseString(self::threeStatements(), self::FILE_NAME);

        $this->assertSame(3, $root->getChildrenCount());

        $children = $root->getChildren();
        $this->assertCount(3, $children);
        $this->assertSame([0, 1, 2], array_keys($children));

        for ($index = 0; $index < 3; $index++) {
            $statement = self::node($children[$index] ?? null);
            $this->assertSame(NodeKind::AST_ASSIGN, $statement->getKind());
            // One statement per source line: node lineno is recorded while parsing
            $this->assertSame($index + 1, $statement->getLine());
        }
    }

    #[RunInSeparateProcess]
    public function testGetChildReturnsTheSameNodesAsGetChildren(): void
    {
        $root     = Core::$compiler->parseString(self::threeStatements(), self::FILE_NAME);
        $children = $root->getChildren();

        for ($index = 0; $index < 3; $index++) {
            $fromList  = self::node($children[$index]);
            $fromIndex = self::node($root->getChild($index));

            $this->assertSame($fromList->getKind(), $fromIndex->getKind());
            $this->assertSame($fromList->getLine(), $fromIndex->getLine());
            $this->assertSame($fromList->dump(), $fromIndex->dump());
        }
    }

    #[RunInSeparateProcess]
    public function testGetChildRejectsAnIndexBeyondTheChildrenCount(): void
    {
        $root = Core::$compiler->parseString('$alpha = 42;', self::FILE_NAME);
        $this->assertSame(1, $root->getChildrenCount());

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('Child index is out of range, there are 1 children.');

        $root->getChild(1);
    }

    #[RunInSeparateProcess]
    public function testReplaceChildRejectsAnIndexBeyondTheChildrenCount(): void
    {
        $root        = Core::$compiler->parseString('$alpha = 42;', self::FILE_NAME);
        $replacement = self::node($root->getChild(0));

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('Child index is out of range, there are 1 children.');

        $root->replaceChild(1, $replacement);
    }

    #[RunInSeparateProcess]
    public function testRemoveChildRejectsAnIndexBeyondTheChildrenCount(): void
    {
        $root = Core::$compiler->parseString('$alpha = 42;', self::FILE_NAME);

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('Child index is out of range, there are 1 children.');

        $root->removeChild(1);
    }

    /**
     * NodeFactory dispatches on the kind encoding: AST_ZVAL becomes a ValueNode, a
     * plain (non-list, non-special) kind stays a Node.
     */
    #[RunInSeparateProcess]
    public function testNodeFactoryDispatchesPlainAndValueNodes(): void
    {
        $root = Core::$compiler->parseString('$alpha = 42;', self::FILE_NAME);

        $assignment = self::node($root->getChild(0));
        $this->assertInstanceOf(Node::class, $assignment);
        $this->assertNotInstanceOf(ListNode::class, $assignment);
        $this->assertNotInstanceOf(ValueNode::class, $assignment);
        $this->assertNotInstanceOf(DeclarationNode::class, $assignment);
        $this->assertSame(NodeKind::AST_ASSIGN, $assignment->getKind());
        $this->assertSame(2, $assignment->getChildrenCount());

        $variable = self::node($assignment->getChild(0));
        $this->assertSame(NodeKind::AST_VAR, $variable->getKind());
        $this->assertSame(1, $variable->getChildrenCount());

        // $alpha - the variable name is an AST_ZVAL holding a string
        $variableName = self::valueNode($variable->getChild(0));
        $this->assertSame(NodeKind::AST_ZVAL, $variableName->getKind());
        $variableName->getValue()->getNativeValue($name);
        $this->assertSame('alpha', $name);

        // 42 - the literal is an AST_ZVAL holding an integer
        $literal = self::valueNode($assignment->getChild(1));
        $this->assertSame(NodeKind::AST_ZVAL, $literal->getKind());
        $literal->getValue()->getNativeValue($value);
        $this->assertSame(42, $value);
    }

    /**
     * A value node carries no children and keeps its line in the zval extra slot
     * (u2.lineno) rather than in a lineno field of its own.
     */
    #[RunInSeparateProcess]
    public function testValueNodeHasNoChildrenAndReadsItsLineFromTheZval(): void
    {
        $root = Core::$compiler->parseString(self::threeStatements(), self::FILE_NAME);

        $secondAssignment = self::node($root->getChild(1));
        $secondVariable   = self::node($secondAssignment->getChild(0));
        $variableName     = self::valueNode($secondVariable->getChild(0));

        $this->assertSame(2, $variableName->getLine());
        $this->assertSame(0, $variableName->getChildrenCount());
        $this->assertSame([], $variableName->getChildren());

        $variableName->getValue()->getNativeValue($name);
        $this->assertSame('second', $name);
    }

    /**
     * A declaration is stored in a zend_ast_decl, which the factory recognises through
     * the "special" bit and always exposes as exactly four children slots.
     */
    #[RunInSeparateProcess]
    public function testNodeFactoryDispatchesDeclarationNodes(): void
    {
        $root = Core::$compiler->parseString(self::functionDeclaration(), self::FILE_NAME);

        $declaration = self::declarationNode($root->getChild(0));
        $this->assertSame(NodeKind::AST_FUNC_DECL, $declaration->getKind());
        $this->assertSame('AST_FUNC_DECL', NodeKind::name($declaration->getKind()));
        $this->assertSame('zEngineParsedFixture', $declaration->getName());
        $this->assertSame('', $declaration->getDocComment());
        $this->assertSame(0, $declaration->getFlags());

        // zend_ast_decl always carries child[4], unused slots stay NULL
        $this->assertSame(4, $declaration->getChildrenCount());
        $children = $declaration->getChildren();
        $this->assertCount(4, $children);

        $childKinds = [];
        foreach ($children as $child) {
            if ($child !== null) {
                $childKinds[] = $child->getKind();
            }
        }
        // The parameter list and the body are the two slots this fixture fills
        $this->assertContains(NodeKind::AST_PARAM_LIST, $childKinds);
        $this->assertContains(NodeKind::AST_STMT_LIST, $childKinds);

        // A declaration spans several lines: getLine() is the start, getEndLine() the end
        $this->assertSame(1, $declaration->getLine());
        $this->assertGreaterThan($declaration->getLine(), $declaration->getEndLine());
    }

    #[RunInSeparateProcess]
    public function testSetLineWritesThroughToTheEngineNode(): void
    {
        $root       = Core::$compiler->parseString('$alpha = 42;', self::FILE_NAME);
        $assignment = self::node($root->getChild(0));

        $originalLine = $assignment->getLine();
        $this->assertSame(1, $originalLine);

        try {
            $assignment->setLine(4242);
            $this->assertSame(4242, $assignment->getLine());
            // A freshly built wrapper sees the same engine node, so the write persisted
            $this->assertSame(4242, self::node($root->getChild(0))->getLine());
        } finally {
            $assignment->setLine($originalLine);
        }

        $this->assertSame($originalLine, self::node($root->getChild(0))->getLine());
    }

    #[RunInSeparateProcess]
    public function testAttributesRoundTrip(): void
    {
        $root     = Core::$compiler->parseString('$alpha = 42;', self::FILE_NAME);
        $variable = self::node(self::node($root->getChild(0))->getChild(0));

        $originalAttributes = $variable->getAttributes();

        try {
            // setAttributes() returns the value it has just written
            $this->assertSame(0x11, $variable->setAttributes(0x11));
            $this->assertSame(0x11, $variable->getAttributes());
            $this->assertSame(0x11, self::node(self::node($root->getChild(0))->getChild(0))->getAttributes());
        } finally {
            $variable->setAttributes($originalAttributes);
        }

        $this->assertSame($originalAttributes, $variable->getAttributes());
    }

    /**
     * replaceChild() writes straight into the zend_ast child slot. Swapping two
     * children keeps every node in the tree exactly once, so the later
     * zend_ast_destroy() still visits each of them exactly once.
     */
    #[RunInSeparateProcess]
    public function testReplaceChildSwapsChildrenInPlace(): void
    {
        $root = Core::$compiler->parseString('$alpha = 1; $beta++;', self::FILE_NAME);
        $this->assertSame(2, $root->getChildrenCount());

        $first  = self::node($root->getChild(0));
        $second = self::node($root->getChild(1));

        $this->assertSame(NodeKind::AST_ASSIGN, $first->getKind());
        $this->assertSame(NodeKind::AST_POST_INC, $second->getKind());

        $root->replaceChild(0, $second);
        $root->replaceChild(1, $first);

        $this->assertSame(NodeKind::AST_POST_INC, self::node($root->getChild(0))->getKind());
        $this->assertSame(NodeKind::AST_ASSIGN, self::node($root->getChild(1))->getKind());
    }

    /**
     * removeChild() clears the child pointer and hands the detached node back. The slot
     * itself stays, so the children count is unchanged - and the node has to be linked
     * back in before the tree is released, otherwise its payload references leak.
     */
    #[RunInSeparateProcess]
    public function testRemoveChildDetachesTheNodeAndReturnsIt(): void
    {
        $root = Core::$compiler->parseString('$alpha = 1; $beta++;', self::FILE_NAME);

        $removed = $root->removeChild(1);

        try {
            $this->assertSame(NodeKind::AST_POST_INC, $removed->getKind());
            $this->assertNull($root->getChild(1));
            $this->assertSame(2, $root->getChildrenCount());
            $this->assertSame([0, 1], array_keys($root->getChildren()));
        } finally {
            $root->replaceChild(1, $removed);
        }

        $this->assertSame(NodeKind::AST_POST_INC, self::node($root->getChild(1))->getKind());
    }

    #[RunInSeparateProcess]
    public function testDumpRendersTheWholeTree(): void
    {
        $root = Core::$compiler->parseString(self::functionDeclaration(), self::FILE_NAME);

        $dump = $root->dump();

        $this->assertNotSame('', $dump);
        $this->assertStringContainsString('AST_STMT_LIST', $dump);
        $this->assertStringContainsString('AST_FUNC_DECL', $dump);
        $this->assertStringContainsString('AST_PARAM_LIST', $dump);
        $this->assertStringContainsString('AST_ZVAL', $dump);
        // DeclarationNode appends the declared name, ValueNode the exported value
        $this->assertStringContainsString('zEngineParsedFixture', $dump);
        $this->assertStringContainsString("string('number')", $dump);
        $this->assertGreaterThan(1, substr_count($dump, "\n"));
    }

    #[RunInSeparateProcess]
    public function testDumpIndentsNestedNodes(): void
    {
        $root = Core::$compiler->parseString('$alpha = 42;', self::FILE_NAME);

        $flat     = $root->dump();
        $indented = $root->dump(1);

        $this->assertNotSame($flat, $indented);
        $this->assertStringContainsString(': AST_STMT_LIST', $flat);
        $this->assertStringContainsString(':   AST_STMT_LIST', $indented);
    }

    /**
     * Three single-statement lines - the line number of each statement is its index + 1
     */
    private static function threeStatements(): string
    {
        return implode("\n", ['$first = 1;', '$second = 2;', '$third = 3;']);
    }

    private static function functionDeclaration(): string
    {
        return implode("\n", [
            'function zEngineParsedFixture(int $number)',
            '{',
            '    return $number * 2;',
            '}',
        ]);
    }

    /**
     * Narrows a nullable child to a present node
     */
    private static function node(?NodeInterface $node): NodeInterface
    {
        assert($node instanceof NodeInterface);

        return $node;
    }

    /**
     * Narrows a nullable child to a ValueNode (an AST_ZVAL leaf)
     */
    private static function valueNode(?NodeInterface $node): ValueNode
    {
        assert($node instanceof ValueNode);

        return $node;
    }

    /**
     * Narrows a nullable child to a DeclarationNode (a zend_ast_decl)
     */
    private static function declarationNode(?NodeInterface $node): DeclarationNode
    {
        assert($node instanceof DeclarationNode);

        return $node;
    }
}
