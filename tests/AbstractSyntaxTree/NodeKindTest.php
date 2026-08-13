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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the classification helpers of NodeKind.
 *
 * isSpecial()/isList()/childrenCount() are pure bit arithmetic over the zend_ast_kind
 * enum encoding (zend_ast.h): bit 6 marks a special node, bit 7 a list node and the
 * high bits carry the fixed number of children. They touch no engine memory, so this
 * suite stays in the default group. EngineConstantsTest separately guards that the
 * constant VALUES still match the generated ground truth; here we only assert that the
 * decoding of those values is right, because NodeFactory dispatches on it.
 */
final class NodeKindTest extends TestCase
{
    /**
     * Kind => [isSpecial, isList, childrenCount]
     *
     * @return array<string, array{0: int, 1: bool, 2: bool, 3: int}>
     */
    public static function kindClassificationProvider(): array
    {
        return [
            'AST_MAGIC_CONST' => [NodeKind::AST_MAGIC_CONST, false, false, 0],
            'AST_TYPE'        => [NodeKind::AST_TYPE, false, false, 0],
            'AST_ZVAL'        => [NodeKind::AST_ZVAL, true, false, 0],
            'AST_FUNC_DECL'   => [NodeKind::AST_FUNC_DECL, true, false, 0],
            'AST_CLOSURE'     => [NodeKind::AST_CLOSURE, true, false, 0],
            'AST_METHOD'      => [NodeKind::AST_METHOD, true, false, 0],
            'AST_CLASS'       => [NodeKind::AST_CLASS, true, false, 0],
            'AST_ARG_LIST'    => [NodeKind::AST_ARG_LIST, false, true, 0],
            'AST_STMT_LIST'   => [NodeKind::AST_STMT_LIST, false, true, 0],
            'AST_PARAM_LIST'  => [NodeKind::AST_PARAM_LIST, false, true, 0],
            'AST_VAR'         => [NodeKind::AST_VAR, false, false, 1],
            'AST_ECHO'        => [NodeKind::AST_ECHO, false, false, 1],
            'AST_POST_INC'    => [NodeKind::AST_POST_INC, false, false, 1],
            'AST_ASSIGN'      => [NodeKind::AST_ASSIGN, false, false, 2],
            'AST_BINARY_OP'   => [NodeKind::AST_BINARY_OP, false, false, 2],
            'AST_CONDITIONAL' => [NodeKind::AST_CONDITIONAL, false, false, 3],
            'AST_TRY'         => [NodeKind::AST_TRY, false, false, 3],
            'AST_FOR'         => [NodeKind::AST_FOR, false, false, 4],
            'AST_FOREACH'     => [NodeKind::AST_FOREACH, false, false, 4],
        ];
    }

    #[DataProvider('kindClassificationProvider')]
    public function testKindClassification(int $astKind, bool $isSpecial, bool $isList, int $childrenCount): void
    {
        $this->assertSame($isSpecial, NodeKind::isSpecial($astKind));
        $this->assertSame($isList, NodeKind::isList($astKind));
        $this->assertSame($childrenCount, NodeKind::childrenCount($astKind));
    }

    /**
     * A node is never both a list and a declaration: NodeFactory checks the special bit
     * first, so an overlap would route list nodes into zend_ast_decl casts.
     */
    public function testListAndSpecialKindsNeverOverlap(): void
    {
        $checked = 0;
        foreach ((new \ReflectionClass(NodeKind::class))->getReflectionConstants() as $constant) {
            if (!$constant->isPublic()) {
                continue;
            }
            $value = $constant->getValue();
            assert(is_int($value));
            $this->assertFalse(
                NodeKind::isList($value) && NodeKind::isSpecial($value),
                $constant->getName() . ' is classified as both a list and a special node',
            );
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'No AST kind constants were classified');
    }

    public function testNameResolvesKnownKinds(): void
    {
        $this->assertSame('AST_ZVAL', NodeKind::name(NodeKind::AST_ZVAL));
        $this->assertSame('AST_STMT_LIST', NodeKind::name(NodeKind::AST_STMT_LIST));
        $this->assertSame('AST_FUNC_DECL', NodeKind::name(NodeKind::AST_FUNC_DECL));
        $this->assertSame('AST_ASSIGN', NodeKind::name(NodeKind::AST_ASSIGN));
    }

    /**
     * The lookup table is built lazily and cached in a static property - a second call
     * must resolve from the cache and return the very same name.
     */
    public function testNameIsStableAcrossCalls(): void
    {
        $first  = NodeKind::name(NodeKind::AST_VAR);
        $second = NodeKind::name(NodeKind::AST_VAR);

        $this->assertSame('AST_VAR', $first);
        $this->assertSame($first, $second);
    }

    public function testNameRejectsUnknownKind(): void
    {
        // Far above every zend_ast_kind value: a kind the generator has never emitted
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unknown code 65535');

        NodeKind::name(65535);
    }
}
