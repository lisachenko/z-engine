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

namespace ZEngine\PHPStan;

use FFI\CData;
use FFI\CType;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Requires every public raw-CData accessor of the core layer to be marked `@internal`
 *
 * AGENTS.md puts every `@internal` method off-limits to packages built on z-engine. A
 * public method in `ZEngine\Type` or `ZEngine\Reflection` that returns a raw `FFI\CData` /
 * `FFI\CType`, or a generated struct stub view (`ZEngine\Generated\*`, a CData handle at
 * run time), is one of those escape hatches, so it must say so (issue #194). The marker
 * on a whole class (StructArray, FunctionLikeInterface) covers its methods.
 *
 * PHPStan reads only the docblock right above a method: an `@internal` in an earlier,
 * stacked docblock is lost, and this rule reports it like a missing one.
 *
 * @implements Rule<InClassMethodNode>
 */
final class RawEngineAccessorRule implements Rule
{
    private const array GUARDED_NAMESPACES = ['ZEngine\\Type\\', 'ZEngine\\Reflection\\'];

    private const array RAW_CLASSES = [CData::class, CType::class];

    private const string STUB_NAMESPACE = 'ZEngine\\Generated\\';

    /**
     * Raw accessors that stay public until their API contract is decided, keyed
     * `Class::method` => tracking issue
     */
    private const array EXEMPT_METHODS = [
        // TODO(#192): AbstractModule exposes the globals through this method
        'ZEngine\\Reflection\\ReflectionExtension::getGlobals' => '#192',
    ];

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $method = $node->getMethodReflection();
        $class  = $node->getClassReflection();
        if (!$method->isPublic() || $class->isInternal() || $method->isInternal()->yes()) {
            return [];
        }
        if (!$this->isGuarded($class->getName())) {
            return [];
        }
        if (isset(self::EXEMPT_METHODS[$method->getDeclaringClass()->getName() . '::' . $method->getName()])) {
            return [];
        }

        $rawClass = $this->findRawReturnClass($node);
        if ($rawClass === null) {
            return [];
        }

        $message = sprintf(
            'Public method %s::%s() returns raw %s but is not marked @internal (see AGENTS.md, "Public APIs never leak CData").',
            $class->getDisplayName(),
            $method->getName(),
            $rawClass,
        );

        return [
            RuleErrorBuilder::message($message)
                ->identifier('zengine.rawAccessorNotInternal')
                ->tip('Add an @internal tag to the method docblock, in the same block as its @return.')
                ->build(),
        ];
    }

    private function isGuarded(string $className): bool
    {
        foreach (self::GUARDED_NAMESPACES as $namespace) {
            if (str_starts_with($className, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the first raw engine class the method's return type refers to, or null
     */
    private function findRawReturnClass(InClassMethodNode $node): ?string
    {
        foreach ($node->getMethodReflection()->getVariants() as $variant) {
            foreach ($variant->getReturnType()->getReferencedClasses() as $referencedClass) {
                if (in_array($referencedClass, self::RAW_CLASSES, true) || str_starts_with($referencedClass, self::STUB_NAMESPACE)) {
                    return $referencedClass;
                }
            }
        }

        return null;
    }
}
