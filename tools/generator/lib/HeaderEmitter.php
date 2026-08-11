<?php

declare(strict_types=1);

namespace ZEngine\Generator;

use RuntimeException;

/**
 * Emits the FFI engine header from the clang AST index: takes the manifest
 * root symbols, resolves all transitively required type declarations and
 * writes them out in original file order (which is always a valid C
 * declaration order, because the original translation unit compiled).
 */
final class HeaderEmitter
{
    /**
     * Built-in type names PHP FFI understands natively - never emitted,
     * and treated as terminals by the dependency walk.
     */
    private const FFI_BUILTIN_TYPES = [
        'void', 'char', 'short', 'int', 'long', 'float', 'double', 'signed', 'unsigned',
        'bool', '_Bool', 'size_t', 'ssize_t', 'ptrdiff_t', 'off_t',
        'int8_t', 'int16_t', 'int32_t', 'int64_t',
        'uint8_t', 'uint16_t', 'uint32_t', 'uint64_t',
        'intptr_t', 'uintptr_t', 'intmax_t', 'uintmax_t',
        'va_list', '__builtin_va_list', '__gnuc_va_list',
    ];

    /** @var array<string, true> */
    private array $neededTypedefs = [];

    /** @var array<string, true> */
    private array $neededRecords = [];

    /** @var array<string, true> */
    private array $neededEnums = [];

    /** @var array<string, true> */
    private array $visited = [];

    /**
     * @param list<string> $types     manifest type names
     * @param list<string> $functions manifest function names
     * @param list<string> $variables manifest variable names
     * @param list<string> $opaque    type names force-degraded to opaque forward declarations
     */
    public function __construct(
        private readonly ClangAstIndex $index,
        private readonly array $types,
        private readonly array $functions,
        private readonly array $variables,
        private readonly array $opaque = [],
    ) {}

    public function emit(): string
    {
        foreach ($this->types as $type) {
            $this->require($type);
        }

        $functionDeclarations = [];
        foreach ($this->functions as $function) {
            $functionDeclarations[] = $this->emitFunction($function);
        }
        $variableDeclarations = [];
        foreach ($this->variables as $variable) {
            $variableDeclarations[] = $this->emitVariable($variable);
        }

        // Collect all needed declarations with their source order position
        /** @var list<array{offset: int, end: int, text: string}> $declarations */
        $declarations = [];
        foreach (array_keys($this->neededTypedefs) as $name) {
            $declaration = $this->typedefDeclaration($name);
            if ($declaration !== null) {
                $declarations[] = $declaration;
            }
        }
        foreach (array_keys($this->neededRecords) as $tag) {
            foreach ($this->recordDeclarations($tag) as $declaration) {
                $declarations[] = $declaration;
            }
        }
        foreach (array_keys($this->neededEnums) as $tag) {
            $enum = $this->index->enum($tag);
            if ($enum !== null) {
                $declarations[] = [
                    'offset' => $this->index->startOffset($enum),
                    'end'    => $this->index->endOffset($enum),
                    'text'   => $this->index->sliceText($enum) . ';',
                ];
            }
        }
        usort($declarations, static fn(array $a, array $b): int => $a['offset'] <=> $b['offset']);

        // Drop declarations textually contained in another one (e.g. the
        // record body of "typedef union _zend_value {...} zend_value;" must
        // not be emitted twice).
        $kept = [];
        foreach ($declarations as $declaration) {
            foreach ($declarations as $other) {
                if ($other !== $declaration
                    && $other['offset'] <= $declaration['offset']
                    && $other['end'] >= $declaration['end']
                    && ($other['end'] - $other['offset']) > ($declaration['end'] - $declaration['offset'])
                ) {
                    continue 2;
                }
            }
            $kept[] = $declaration;
        }

        $body = implode("\n", array_map(static fn(array $declaration): string => $declaration['text'], $kept));

        $header = self::cleanForFfi($body)
            . "\n\n/* Imported functions */\n" . implode("\n", $functionDeclarations)
            . "\n\n/* Imported globals */\n" . implode("\n", $variableDeclarations)
            . "\n";
        self::assertNoZeroArgumentVectorcall($header);

        return $header;
    }

    /**
     * Record type names (typedefs and struct/union tags) that resolved into
     * the emitted header with a full definition - the manifest roots plus
     * every transitively required record. Callable after emit(); the stub
     * emitter derives the engine struct stub set from this list.
     *
     * @return list<string>
     */
    public function resolvedRecordTypes(): array
    {
        $names = [];
        foreach (array_keys($this->neededTypedefs) as $name) {
            if (!in_array($name, $this->opaque, true) && $this->index->resolveRecord($name) !== null) {
                $names[$name] = true;
            }
        }
        foreach (array_keys($this->neededRecords) as $tag) {
            if (!in_array($tag, $this->opaque, true) && $this->index->fullRecordDefinition($tag) !== null) {
                $names[$tag] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Marks a type name (typedef, struct/union tag or enum tag) and all its
     * transitive dependencies as required.
     */
    private function require(string $name): void
    {
        if (isset($this->visited[$name]) || in_array($name, self::FFI_BUILTIN_TYPES, true)) {
            return;
        }
        $this->visited[$name] = true;

        if (in_array($name, $this->opaque, true)) {
            // Opaque: keep only the typedef (pointer-sized usage); the
            // underlying record is declared as an incomplete forward type.
            if ($this->index->hasTypedef($name)) {
                $this->neededTypedefs[$name] = true;
                $tag                         = $this->index->typedefUnderlyingTag($name);
                if ($tag !== null) {
                    $this->neededRecords[$tag] = true;
                }
            } elseif ($this->index->hasRecord($name)) {
                $this->neededRecords[$name] = true;
            }

            return;
        }

        $found = false;
        if ($this->index->hasTypedef($name)) {
            $this->neededTypedefs[$name] = true;
            $typedef                     = $this->index->typedef($name);
            assert($typedef !== null);
            $this->requireIdentifiersOf($this->index->sliceText($typedef));
            $found = true;
        }
        if ($this->index->hasRecord($name)) {
            $this->neededRecords[$name] = true;
            $full                       = $this->index->fullRecordDefinition($name);
            if ($full !== null) {
                $this->requireIdentifiersOf($this->index->sliceText($full));
            }
            $found = true;
        }
        if ($this->index->hasEnum($name)) {
            $this->neededEnums[$name] = true;
            $found                    = true;
        }
        if (!$found && in_array($name, $this->types, true)) {
            throw new RuntimeException("Manifest type '{$name}' was not found in the engine headers");
        }
    }

    private function requireIdentifiersOf(string $code): void
    {
        preg_match_all('/[A-Za-z_][A-Za-z0-9_]*+/', $code, $matches);
        foreach (array_unique($matches[0]) as $identifier) {
            if ($this->index->hasTypedef($identifier)
                || $this->index->hasRecord($identifier)
                || $this->index->hasEnum($identifier)
            ) {
                $this->require($identifier);
            }
        }
    }

    /**
     * @return array{offset: int, end: int, text: string}|null
     */
    private function typedefDeclaration(string $name): ?array
    {
        $typedef = $this->index->typedef($name);
        if ($typedef === null) {
            return null;
        }
        $offset = $this->index->startOffset($typedef);

        // An opaque typedef must never slice its source declaration when that
        // declaration defines the record inline (Darwin's "typedef struct
        // __sFILE { ... } FILE;" - unlike glibc, which forward-declares the
        // tag separately): the slice would drag the full libc body and its
        // by-value member types into the header. Synthesize the bodyless
        // form instead; the zero-length range keeps the containment dedup
        // from swallowing the record's own forward declaration.
        $tag = $this->index->typedefUnderlyingTag($name);
        if ($tag !== null && in_array($name, $this->opaque, true)) {
            $keyword = $this->index->isUnion($tag) ? 'union' : 'struct';

            return [
                'offset' => $offset,
                'end'    => $offset,
                'text'   => "typedef {$keyword} {$tag} {$name};",
            ];
        }

        return [
            'offset' => $offset,
            'end'    => $this->index->endOffset($typedef),
            'text'   => $this->index->sliceText($typedef) . ';',
        ];
    }

    /**
     * All declarations for a record tag: one forward declaration at the first
     * occurrence (needed for self-referential pointers) plus the full
     * definition. For opaque records only the forward declaration is kept.
     *
     * @return list<array{offset: int, end: int, text: string}>
     */
    private function recordDeclarations(string $tag): array
    {
        $all  = $this->index->recordDeclarations($tag);
        $full = $this->index->fullRecordDefinition($tag);

        $declarations = [];
        $isOpaque     = $full === null
            || in_array($tag, $this->opaque, true)
            || ($this->typedefNameOf($tag) !== null && in_array($this->typedefNameOf($tag), $this->opaque, true));

        if ($all !== []) {
            $first          = $all[0];
            $keyword        = ($first['tagUsed'] ?? 'struct') === 'union' ? 'union' : 'struct';
            $position       = $this->index->startOffset($first);
            $declarations[] = [
                'offset' => $position,
                'end'    => $position,
                'text'   => "{$keyword} {$tag};",
            ];
        }
        if (!$isOpaque && $full !== null) {
            $declarations[] = [
                'offset' => $this->index->startOffset($full),
                'end'    => $this->index->endOffset($full),
                'text'   => $this->index->sliceText($full) . ';',
            ];
        }

        return $declarations;
    }

    private function typedefNameOf(string $tag): ?string
    {
        foreach ($this->types as $type) {
            if ($this->index->typedefUnderlyingTag($type) === $tag) {
                return $type;
            }
        }

        return null;
    }

    private function emitFunction(string $name): string
    {
        $declaration = $this->index->function($name);
        if ($declaration === null) {
            throw new RuntimeException("Manifest function '{$name}' was not found in the engine headers");
        }
        $qualType = $declaration['type']['qualType'] ?? '';
        if (!is_string($qualType)) {
            throw new RuntimeException("Cannot parse type of function '{$name}': " . var_export($qualType, true));
        }
        $this->requireIdentifiersOf($qualType);

        // ZEND_FASTCALL is __vectorcall on MSVC, which clang reports as a
        // trailing attribute on the function type. PHP FFI only understands
        // the keyword spelling: it parses __vectorcall and mangles the symbol
        // lookup into the decorated export name (name@@N) MSVC produces, while
        // __attribute__((vectorcall)) parses but is silently ignored - wrong
        // calling convention and an undecorated, unresolvable symbol.
        $count      = 0;
        $qualType   = (string) preg_replace('/\s*__attribute__\s*\(\(vectorcall\)\)\s*$/', '', $qualType, 1, $count);
        $vectorcall = $count > 0;

        if (($position = strpos($qualType, '(')) === false) {
            throw new RuntimeException("Cannot parse type of function '{$name}': " . var_export($qualType, true));
        }
        $returnType = rtrim(substr($qualType, 0, $position));
        $parameters = substr($qualType, $position);
        if (!$vectorcall) {
            return "extern {$returnType} {$name}{$parameters};";
        }
        if (preg_match('/^\(\s*(?:void\s*)?\)$/', $parameters) === 1) {
            throw new RuntimeException(
                "Function '{$name}' is a zero-argument __vectorcall declaration, which crashes PHP FFI "
                . '(NULL dereference while building the argument types) - it cannot be declared at all',
            );
        }

        return "extern {$returnType} __vectorcall {$name}{$parameters};";
    }

    /**
     * PHP FFI dereferences a NULL pointer while building the argument types of
     * a zero-argument __vectorcall function, so such a declaration cannot even
     * be parsed - it takes the whole process down. emitFunction() guards the
     * declarations it writes itself; this scan additionally covers everything
     * sliced verbatim out of the engine headers (a `void (__vectorcall *f)(void)`
     * handler typedef would be just as fatal).
     */
    private static function assertNoZeroArgumentVectorcall(string $code): void
    {
        if (preg_match('/__vectorcall\b[^;]*?\(\s*(?:void\s*)?\)\s*(?:;|\z)/', $code, $matches) === 1) {
            throw new RuntimeException(
                'Emitted header contains a zero-argument __vectorcall declaration, which crashes PHP FFI: '
                . trim($matches[0]),
            );
        }
    }

    private function emitVariable(string $name): string
    {
        $declaration = $this->index->variable($name);
        if ($declaration === null) {
            throw new RuntimeException("Manifest variable '{$name}' was not found in the engine headers");
        }
        $qualType = $declaration['type']['qualType'] ?? '';
        if (!is_string($qualType) || $qualType === '') {
            throw new RuntimeException("Cannot parse type of variable '{$name}'");
        }
        $this->requireIdentifiersOf($qualType);

        // Array-typed globals: the array suffix belongs after the declarator
        // name (clang reports `char[32]`, C requires `char zend_system_id[32]`)
        if (preg_match('/^(.*?)\s*((?:\[[^\]]*\])+)$/', $qualType, $matches) === 1) {
            return 'extern ' . trim($matches[1]) . " {$name}{$matches[2]};";
        }

        // Function-pointer globals without a typedef (zend_error_cb & friends):
        // the declarator name belongs inside the pointer parentheses (clang
        // reports `void (*)(int)`, C requires `void (*zend_error_cb)(int)`)
        if (preg_match('/^(.*?)\(\*\)\s*(\(.*\))$/s', $qualType, $matches) === 1) {
            return 'extern ' . trim($matches[1]) . " (*{$name}){$matches[2]};";
        }

        return "extern {$qualType} {$name};";
    }

    /**
     * Cleans compiler-specific constructs PHP FFI cannot parse from the
     * emitted declarations.
     */
    public static function cleanForFfi(string $code): string
    {
        // __attribute__((...)) with balanced inner parentheses
        $code = (string) preg_replace('/__attribute__\s*\(\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)\)/', '', $code);
        // asm renaming / inline asm remnants on declarations
        $code = (string) preg_replace('/\b(?:__asm__|asm)\s*(?:__volatile__\s*)?\((?:[^()]|\([^()]*\))*\)/', '', $code);
        // _Atomic(T) -> T, _Atomic qualifier -> nothing
        $code = (string) preg_replace('/\b_Atomic\s*\(([^()]*)\)/', '$1', $code);
        $code = (string) preg_replace('/\b_Atomic\b/', '', $code);
        // Alignment specifiers
        $code = (string) preg_replace('/\b_Alignas\s*\((?:[^()]|\([^()]*\))*\)/', '', $code);
        // GCC keywords FFI does not know
        $code = (string) preg_replace('/\b(?:__extension__|__restrict__|__restrict|restrict|__volatile__|__inline__|__inline|__signed__)\b/', '', $code);
        // MSVC keywords leaking in from the Windows SDK headers. __vectorcall
        // is deliberately NOT in this list (FFI parses it and needs it to
        // mangle x64 symbol lookups into MSVC's decorated name@@N exports), and
        // neither is __declspec (FFI parses it too, and honours
        // __declspec(align(N)) - jmp_buf's layout depends on it).
        $code = (string) preg_replace('/\b(?:__forceinline|__ptr64|__unaligned|__stdcall|__cdecl|__fastcall|__thiscall)\b/', '', $code);
        $code = (string) preg_replace('/\b__pragma\s*\((?:[^()]|\([^()]*\))*\)/', '', $code);
        // MSVC's spelling of the 64-bit integer type; identical type, but FFI
        // only knows the standard name.
        $code = (string) preg_replace('/\b__int64\b/', 'long long', $code);
        // Static assertions are statements, not declarations
        $code = (string) preg_replace('/\b_Static_assert\s*\((?:[^()]|\([^()]*\))*\)\s*;/', '', $code);

        return $code;
    }
}
