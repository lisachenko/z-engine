<?php

declare(strict_types=1);

namespace ZEngine\Generator;

use RuntimeException;

/**
 * Index over the clang JSON AST of the preprocessed engine headers.
 *
 * Only top-level declarations are indexed: typedefs, record (struct/union)
 * declarations, enums, functions and global variables. Every declaration
 * keeps its byte range into the preprocessed source, so its original text
 * can be sliced back out verbatim.
 *
 * @phpstan-type DeclNode array<string, mixed>
 */
final class ClangAstIndex
{
    /** @var array<string, DeclNode> typedef name => declaration node */
    private array $typedefs = [];

    /** @var array<string, list<DeclNode>> struct/union tag => declaration nodes (forward + full) */
    private array $records = [];

    /** @var array<string, DeclNode> enum tag => full declaration node */
    private array $enums = [];

    /** @var array<string, DeclNode> function name => declaration node */
    private array $functions = [];

    /** @var array<string, DeclNode> variable name => declaration node */
    private array $variables = [];

    private function __construct(private readonly string $source) {}

    public static function fromFiles(string $preprocessedFile, string $astJsonFile): self
    {
        $source = file_get_contents($preprocessedFile);
        if ($source === false) {
            throw new RuntimeException("Cannot read {$preprocessedFile}");
        }
        $astJson = file_get_contents($astJsonFile);
        if ($astJson === false) {
            throw new RuntimeException("Cannot read {$astJsonFile}");
        }
        /** @var array{inner?: list<array<string, mixed>>}|null $ast */
        $ast = json_decode($astJson, true, 131072);
        if (!is_array($ast) || !isset($ast['inner'])) {
            throw new RuntimeException("Cannot decode clang AST from {$astJsonFile}: " . json_last_error_msg());
        }

        $index = new self($source);
        foreach ($ast['inner'] as $decl) {
            $index->registerDeclaration($decl);
        }

        return $index;
    }

    /**
     * @param DeclNode $decl
     */
    private function registerDeclaration(array $decl): void
    {
        $kind = $decl['kind'] ?? '';
        $name = $decl['name'] ?? '';
        if (!is_string($kind) || !is_string($name)) {
            return;
        }
        switch ($kind) {
            case 'TypedefDecl':
                // Keep the first occurrence: later re-typedefs are always identical in C
                if ($name !== '' && !isset($this->typedefs[$name]) && $this->hasRange($decl)) {
                    $this->typedefs[$name] = $decl;
                }
                break;
            case 'RecordDecl':
                if ($name !== '' && $this->hasRange($decl)) {
                    $this->records[$name][] = $decl;
                }
                break;
            case 'EnumDecl':
                if ($name !== '' && !isset($this->enums[$name]) && $this->hasRange($decl)) {
                    $this->enums[$name] = $decl;
                }
                break;
            case 'FunctionDecl':
                if ($name !== '' && !isset($this->functions[$name])) {
                    $this->functions[$name] = $decl;
                }
                break;
            case 'VarDecl':
                if ($name !== '' && !isset($this->variables[$name])) {
                    $this->variables[$name] = $decl;
                }
                break;
        }
    }

    /**
     * @param DeclNode $decl
     */
    private function hasRange(array $decl): bool
    {
        return isset($decl['range']['begin']['offset'], $decl['range']['end']['offset'], $decl['range']['end']['tokLen']);
    }

    /**
     * Slices the original source text of a declaration (without trailing semicolon).
     *
     * @param DeclNode $decl
     */
    public function sliceText(array $decl): string
    {
        $begin = $decl['range']['begin']['offset'];
        $end   = $decl['range']['end']['offset'] + $decl['range']['end']['tokLen'];
        assert(is_int($begin) && is_int($end));

        return substr($this->source, $begin, $end - $begin);
    }

    /**
     * @param DeclNode $decl
     */
    public function startOffset(array $decl): int
    {
        $offset = $decl['range']['begin']['offset'] ?? PHP_INT_MAX;
        assert(is_int($offset));

        return $offset;
    }

    /**
     * @param DeclNode $decl
     */
    public function endOffset(array $decl): int
    {
        $offset = $decl['range']['end']['offset'] ?? PHP_INT_MAX;
        $tokLen = $decl['range']['end']['tokLen'] ?? 0;
        assert(is_int($offset) && is_int($tokLen));

        return $offset + $tokLen;
    }

    /**
     * @return DeclNode|null
     */
    public function typedef(string $name): ?array
    {
        return $this->typedefs[$name] ?? null;
    }

    /**
     * @return list<DeclNode>
     */
    public function recordDeclarations(string $tag): array
    {
        return $this->records[$tag] ?? [];
    }

    /**
     * @return DeclNode|null
     */
    public function fullRecordDefinition(string $tag): ?array
    {
        foreach ($this->records[$tag] ?? [] as $decl) {
            if (($decl['completeDefinition'] ?? false) === true) {
                return $decl;
            }
        }

        return null;
    }

    /**
     * @return DeclNode|null
     */
    public function enum(string $tag): ?array
    {
        return $this->enums[$tag] ?? null;
    }

    /**
     * @return DeclNode|null
     */
    public function function(string $name): ?array
    {
        return $this->functions[$name] ?? null;
    }

    /**
     * @return DeclNode|null
     */
    public function variable(string $name): ?array
    {
        return $this->variables[$name] ?? null;
    }

    public function hasTypedef(string $name): bool
    {
        return isset($this->typedefs[$name]);
    }

    public function hasRecord(string $tag): bool
    {
        return isset($this->records[$tag]);
    }

    public function hasEnum(string $tag): bool
    {
        return isset($this->enums[$tag]);
    }

    /**
     * Member names of an enum, in declaration order.
     *
     * @return list<string>
     */
    public function enumMemberNames(string $tag): array
    {
        $names = [];
        $decl  = $this->enums[$tag] ?? null;
        if ($decl !== null) {
            foreach ($decl['inner'] ?? [] as $member) {
                if (($member['kind'] ?? '') === 'EnumConstantDecl' && is_string($member['name'] ?? null)) {
                    $names[] = $member['name'];
                }
            }
        }

        return $names;
    }

    /**
     * Field names of a struct usable with offsetof(): direct non-bitfield
     * fields, in declaration order. Unnamed nested records are skipped
     * (their flattened members are reported by clang as IndirectFieldDecl).
     *
     * @return list<string>
     */
    public function structFieldNames(string $typeName): array
    {
        $record = $this->resolveRecord($typeName);
        if ($record === null) {
            return [];
        }
        $fields = [];
        foreach ($record['inner'] ?? [] as $member) {
            $kind = $member['kind'] ?? '';
            $name = $member['name'] ?? '';
            if (!is_string($name) || $name === '') {
                continue;
            }
            $isBitfield = ($member['isBitfield'] ?? false) === true;
            if (($kind === 'FieldDecl' || $kind === 'IndirectFieldDecl') && !$isBitfield) {
                $fields[] = $name;
            }
        }

        return $fields;
    }

    /**
     * Whether the given type name resolves to a union (offsetof on members is always 0).
     */
    public function isUnion(string $typeName): bool
    {
        $record = $this->resolveRecord($typeName);

        return $record !== null && ($record['tagUsed'] ?? '') === 'union';
    }

    /**
     * Resolves a typedef or tag name to the full record definition node.
     *
     * @return DeclNode|null
     */
    public function resolveRecord(string $typeName): ?array
    {
        $full = $this->fullRecordDefinition($typeName);
        if ($full !== null) {
            return $full;
        }
        $typedef = $this->typedefs[$typeName] ?? null;
        if ($typedef !== null) {
            $qualType = $typedef['type']['qualType'] ?? '';
            if (is_string($qualType) && preg_match('/^(?:struct|union)\s+(\w+)$/', $qualType, $matches) === 1) {
                return $this->fullRecordDefinition($matches[1]);
            }
        }

        return null;
    }

    /**
     * Underlying "struct X"/"union X" tag of a typedef, if any.
     */
    public function typedefUnderlyingTag(string $name): ?string
    {
        $typedef = $this->typedefs[$name] ?? null;
        if ($typedef !== null) {
            $qualType = $typedef['type']['qualType'] ?? '';
            if (is_string($qualType) && preg_match('/^(?:struct|union)\s+(\w+)$/', $qualType, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }
}
