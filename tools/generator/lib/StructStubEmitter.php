<?php

declare(strict_types=1);

namespace ZEngine\Generator;

use RuntimeException;

/**
 * Emits the analysis-only PHP stub classes for the engine structs (and the
 * matching .phpstorm.meta.php) from the clang AST index.
 *
 * One final class per engine struct/union, in the ZEngine\Generated namespace,
 * with every C field declared as a real typed public property. The classes are
 * never autoloadable (stubs/ lives outside the PSR-4 roots): PHPStan sees them
 * via scanFiles, the IDE indexes them as project sources, and the only legal
 * runtime use is the ::class constant, which does not trigger autoloading.
 *
 * Both outputs are pure functions of the AST and the manifest lists - no
 * timestamps, no paths, no platform spellings - and platform-only fields
 * (symbols.php 'stub_platform_fields') are omitted on every target, so the
 * emitted bytes are identical across all supported targets. generate.php
 * enforces that byte-identity when regenerating the non-canonical targets.
 *
 * @phpstan-import-type DeclNode from ClangAstIndex
 */
final class StructStubEmitter
{
    private const STUB_NAMESPACE = 'ZEngine\\Generated';

    /**
     * Engine-owned record names. Everything else reachable from the header
     * (libc/win32 scaffolding such as __sigset_t or OSVERSIONINFOEX) is
     * platform-specific noise and never becomes a stub.
     */
    private const ENGINE_NAME_PATTERN = '/^_?(?:zend_|zval)/';
    private const ENGINE_EXTRA_NAMES  = ['Bucket', 'HashTable', 'HashTableIterator', 'znode_op'];

    /** @var array<string, string> C type name (typedef or tag) => stub class name */
    private array $knownTypes = [];

    /** @var array<string, array{isUnion: bool, properties: list<array{name: string, type: string}>, origin: string}> */
    private array $classes = [];

    private bool $collected = false;

    /**
     * @param list<string>                $candidateTypes     record type names resolved into the
     *                                                        header (HeaderEmitter::resolvedRecordTypes())
     * @param array<string, list<string>> $platformOnlyFields symbols.php 'stub_platform_fields':
     *                                                        #ifdef'd fields omitted on every target
     */
    public function __construct(
        private readonly ClangAstIndex $index,
        private readonly array $candidateTypes,
        private readonly array $platformOnlyFields,
        private readonly string $phpMinor,
    ) {}

    /**
     * Full content of the generated stubs file (stubs/zend-engine-structs.php).
     */
    public function emitStubsFile(): string
    {
        $this->collect();

        $blocks = [];
        foreach ($this->classes as $className => $class) {
            $blocks[] = $this->renderClass($className, $class);
        }

        return $this->stubsBanner() . "\n" . implode("\n", $blocks);
    }

    /**
     * Full content of the generated .phpstorm.meta.php.
     */
    public function emitPhpStormMeta(): string
    {
        $this->collect();

        // Only typedef-named structs are valid FFI type names ("struct _x" tags
        // and the synthetic nested classes are not allocatable/castable by name)
        $typeNames = [];
        foreach ($this->classes as $className => $class) {
            if ($this->index->hasTypedef($className)) {
                $typeNames[] = $className;
            }
        }

        $fqcn        = static fn(string $class): string => '\\' . self::STUB_NAMESPACE . '\\' . $class . '::class';
        $passthrough = "        '' => '@',";
        $newLines    = [$passthrough];
        $castLines   = [$passthrough];
        foreach ($typeNames as $name) {
            $newLines[]  = "        '{$name}' => {$fqcn($name)},";
            $castLines[] = "        '{$name}' => {$fqcn($name)},";
            $castLines[] = "        '{$name} *' => {$fqcn($name)},";
        }

        $overrides = [];
        foreach (['new', 'trackedNew'] as $method) {
            $overrides[] = "override(\\ZEngine\\Core::{$method}(0), map([\n" . implode("\n", $newLines) . "\n]));";
        }
        $overrides[] = "override(\\ZEngine\\Core::cast(0), map([\n" . implode("\n", $castLines) . "\n]));";

        return $this->metaBanner() . "\nnamespace PHPSTORM_META;\n\n" . implode("\n\n", $overrides) . "\n";
    }

    /**
     * Selects the stub set from the candidates and walks every record into
     * property lists. Idempotent; both emitters share the result.
     */
    private function collect(): void
    {
        if ($this->collected) {
            return;
        }
        $this->collected = true;

        // Group the engine-owned candidates by the record they resolve to, so
        // "zend_string" (typedef) and "_zend_string" (tag) yield one class
        // under the typedef name, while "zend_array"/"HashTable" (two typedefs
        // over one record) both stay.
        /** @var array<string, array{node: DeclNode, typedefs: list<string>, tags: list<string>}> $byRecord */
        $byRecord = [];
        foreach ($this->candidateTypes as $name) {
            if (preg_match(self::ENGINE_NAME_PATTERN, $name) !== 1
                && !in_array($name, self::ENGINE_EXTRA_NAMES, true)
            ) {
                continue;
            }
            $record = $this->index->resolveRecord($name);
            if ($record === null) {
                continue;
            }
            $recordId = $record['id'] ?? null;
            if (!is_string($recordId)) {
                throw new RuntimeException("Record '{$name}' has no clang node id");
            }
            $byRecord[$recordId] ??= ['node' => $record, 'typedefs' => [], 'tags' => []];
            $kind = $this->index->hasTypedef($name) ? 'typedefs' : 'tags';
            if (!in_array($name, $byRecord[$recordId][$kind], true)) {
                $byRecord[$recordId][$kind][] = $name;
            }
        }

        /** @var array<string, DeclNode> $stubRecords class name => record node */
        $stubRecords = [];
        foreach ($byRecord as $group) {
            $classNames = $group['typedefs'] !== [] ? $group['typedefs'] : $group['tags'];
            sort($classNames, SORT_STRING);
            foreach ($classNames as $className) {
                $stubRecords[$className] = $group['node'];
            }
            // Every spelling of the record (kept classes, dropped tags, and
            // the typedefs' underlying tags) must map pointer/value field
            // types to one deterministic class: the alphabetically first.
            $canonical = $classNames[0];
            foreach ([...$group['typedefs'], ...$group['tags']] as $name) {
                $this->knownTypes[$name] ??= $canonical;
                $tag = $this->index->typedefUnderlyingTag($name);
                if ($tag !== null) {
                    $this->knownTypes[$tag] ??= $canonical;
                }
            }
            // A kept class name always maps to itself (HashTable * => HashTable,
            // not the shared-record canonical)
            foreach ($classNames as $className) {
                $this->knownTypes[$className] = $className;
            }
        }
        ksort($stubRecords, SORT_STRING);

        foreach ($stubRecords as $className => $record) {
            $this->buildClass($className, $record, $className);
        }
        ksort($this->classes, SORT_STRING);
    }

    /**
     * Registers a class for a record node and, recursively, for every named
     * anonymous record nested inside it (zval's u1 becomes zval_u1, ...).
     *
     * @param array<mixed> $record
     */
    private function buildClass(string $className, array $record, string $origin): void
    {
        if (isset($this->classes[$className])) {
            throw new RuntimeException("Stub class name collision: '{$className}' is emitted twice");
        }
        // Reserve the slot first: fields of self-referential records may need
        // the name while the walk is still running.
        $this->classes[$className] = [
            'isUnion'    => ($record['tagUsed'] ?? '') === 'union',
            'properties' => [],
            'origin'     => $origin,
        ];

        $this->classes[$className]['properties'] = $this->walkFields($record, $className, $origin);
    }

    /**
     * Direct members of a record in declaration order, mapped to PHP property
     * types. A named anonymous nested record binds to the field that follows
     * it and becomes a synthetic sibling class (owner_field); an unnamed one
     * is flattened into the owner, mirroring C semantics (and clang's
     * IndirectFieldDecl reporting, which this walk skips as redundant).
     *
     * @param array<mixed> $record
     *
     * @return list<array{name: string, type: string}>
     */
    private function walkFields(array $record, string $className, string $origin): array
    {
        $skipFields = $this->platformOnlyFields[$className] ?? [];

        $properties = [];
        /** @var array<mixed>|null $pendingAnonymous */
        $pendingAnonymous = null;
        $flushPending     = function () use (&$pendingAnonymous, &$properties, $className, $origin): void {
            if ($pendingAnonymous !== null) {
                foreach ($this->walkFields($pendingAnonymous, $className, $origin) as $flattened) {
                    $properties[] = $flattened;
                }
                $pendingAnonymous = null;
            }
        };

        $members = $record['inner'] ?? [];
        if (!is_array($members)) {
            $members = [];
        }
        foreach ($members as $member) {
            if (!is_array($member)) {
                continue;
            }
            $kind = $member['kind'] ?? '';
            $name = $member['name'] ?? '';
            if ($kind === 'RecordDecl' && $name === '') {
                $flushPending();
                $pendingAnonymous = $member;
                continue;
            }
            if ($kind !== 'FieldDecl' || !is_string($name) || $name === '') {
                continue;
            }
            if (in_array($name, $skipFields, true)) {
                $pendingAnonymous = null;
                continue;
            }
            $type     = is_array($member['type'] ?? null) ? $member['type'] : [];
            $qualType = is_string($type['qualType'] ?? null) ? $type['qualType'] : '';
            if ($pendingAnonymous !== null
                && (str_contains($qualType, '(unnamed') || str_contains($qualType, '(anonymous'))
            ) {
                // "struct { ... } fieldName;" - synthetic sibling class named
                // by the field path, non-null (embedded by value)
                $syntheticName = $className . '_' . $name;
                $this->buildClass($syntheticName, $pendingAnonymous, "{$origin}.{$name}");
                $pendingAnonymous = null;
                $properties[]     = ['name' => $name, 'type' => $syntheticName];
                continue;
            }
            $flushPending();
            if (($member['isBitfield'] ?? false) === true) {
                $properties[] = ['name' => $name, 'type' => 'int'];
                continue;
            }
            $properties[] = ['name' => $name, 'type' => $this->mapType($type)];
        }
        $flushPending();

        return $properties;
    }

    /**
     * Maps a clang field type to the PHP property type. The classification
     * runs on the spelled type first (it preserves engine typedef names) and
     * falls back to the fully desugared form (it collapses platform typedef
     * chains like zend_ulong to one integer classification).
     *
     * @param array<mixed> $type
     */
    private function mapType(array $type): string
    {
        $strip     = static fn(string $t): string => trim((string) preg_replace('/\b(?:const|volatile)\b/', '', $t));
        $qualType  = is_string($type['qualType'] ?? null) ? $strip($type['qualType']) : '';
        $desugared = is_string($type['desugaredQualType'] ?? null) ? $strip($type['desugaredQualType']) : $qualType;

        // Function pointers (spelled or behind a typedef) are opaque handles
        if (str_contains($qualType, '(*)') || str_contains($desugared, '(*)')) {
            return '?\FFI\CData';
        }
        // Arrays (fixed, flexible or unsized) stay indexable CData views
        if (preg_match('/\[\d*\]$/', $qualType) === 1 || preg_match('/\[\d*\]$/', $desugared) === 1) {
            return '\FFI\CData';
        }
        // Single pointer to a stub-known record reads as that stub or null
        // (a NULL pointer field reads as PHP null through FFI)
        if (str_ends_with($qualType, '*') || str_ends_with($desugared, '*')) {
            foreach ([$qualType, $desugared] as $spelling) {
                if (preg_match('/^(?:struct |union )?(\w+)\s*\*$/', $spelling, $matches) === 1
                    && isset($this->knownTypes[$matches[1]])
                ) {
                    return '?' . $this->knownTypes[$matches[1]];
                }
            }

            return '?\FFI\CData';
        }
        // Stub-known record embedded by value
        foreach ([$qualType, $desugared] as $spelling) {
            if (preg_match('/^(?:struct |union )?(\w+)$/', $spelling, $matches) === 1
                && isset($this->knownTypes[$matches[1]])
            ) {
                return $this->knownTypes[$matches[1]];
            }
        }
        // Scalars, classified on the desugared spelling
        if (in_array($desugared, ['bool', '_Bool'], true)) {
            return 'bool';
        }
        if (in_array($desugared, ['float', 'double', 'long double'], true)) {
            return 'float';
        }
        if ($desugared === 'char') {
            // PHP FFI reads/writes a by-value char as a one-byte string
            return 'string';
        }
        if (preg_match('/^(?:signed |unsigned )?(?:char|short|int|long)(?:\s+(?:int|long))?(?:\s+long)?$/', $desugared) === 1
            || preg_match('/^u?int(?:8|16|32|64)_t$/', $desugared)                                                      === 1
        ) {
            return 'int';
        }
        if (str_starts_with($desugared, 'enum ') || $this->index->hasEnum($desugared) || $this->index->hasEnum($qualType)) {
            return 'int';
        }
        // Anything left (by-value non-engine record, exotic scalar) stays an
        // opaque handle
        return '\FFI\CData';
    }

    /**
     * @param array{isUnion: bool, properties: list<array{name: string, type: string}>, origin: string} $class
     */
    private function renderClass(string $className, array $class): string
    {
        $docLines   = [];
        $docLines[] = ' * Analysis-only view of the engine C ' . ($class['isUnion'] ? 'union' : 'struct')
            . " '{$class['origin']}'; the runtime value is always FFI\\CData.";
        if ($class['isUnion']) {
            $docLines[] = ' *';
            $docLines[] = ' * C union - only one member is meaningful at a time; the discriminant lives elsewhere.';
        }
        $docLines[] = ' *';
        $docLines[] = ' * Indexable like the FFI handle it stands for: $view[$i] is the i-th element of';
        $docLines[] = ' * the pointed C array (the ArrayAccess shape is analysis-only, never executed).';
        $docLines[] = ' *';
        $docLines[] = ' * @internal';

        $body = '';
        foreach ($class['properties'] as $property) {
            $body .= "    public {$property['type']} \${$property['name']};\n";
        }
        if ($body !== '') {
            $body .= "\n";
        }
        $body .= <<<'PHP'
            private function __construct() {}

            public function offsetExists(mixed $offset): bool
            {
                return true;
            }

            public function offsetGet(mixed $offset): static
            {
                return $this;
            }

            public function offsetSet(mixed $offset, mixed $value): void {}

            public function offsetUnset(mixed $offset): void {}

        PHP;

        return "/**\n" . implode("\n", $docLines) . "\n */\n"
            . "final class {$className} implements \\ArrayAccess\n{\n{$body}}\n";
    }

    private function stubsBanner(): string
    {
        return <<<PHP
        <?php

        /*
         * Generated by tools/generator for PHP {$this->phpMinor} - DO NOT EDIT.
         * Regenerate with `composer gen-headers`.
         *
         * Analysis-only stub classes for the Zend Engine structs: every C field is a
         * real typed public property, so PHPStan (via scanFiles) and the IDE resolve
         * and autocomplete engine struct access statically. The runtime values remain
         * FFI\CData handles: these classes are never loadable (stubs/ is outside the
         * PSR-4 roots), never instantiated and never instanceof'd - the only legal
         * runtime use is the ::class constant, which does not trigger autoloading.
         *
         * The file is byte-identical across all supported targets: fields that exist
         * only on some targets (#ifdef'd engine fields) are listed in
         * tools/generator/symbols.php 'stub_platform_fields' and omitted everywhere.
         */

        declare(strict_types=1);

        namespace ZEngine\Generated;

        PHP;
    }

    private function metaBanner(): string
    {
        return <<<PHP
        <?php

        /*
         * Generated by tools/generator for PHP {$this->phpMinor} - DO NOT EDIT.
         * Regenerate with `composer gen-headers`.
         *
         * PhpStorm-only type maps for the ZEngine\Core typed entry points: a stub
         * ::class argument passes through ('' => '@'), and the legacy C type name
         * string literals resolve to the same generated stub classes. PHPStan does
         * not read this file - it derives the same types from the conditional-return
         * docblocks on ZEngine\Core plus stubs/zend-engine-structs.php.
         */

        PHP;
    }
}
