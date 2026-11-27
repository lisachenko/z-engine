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

namespace ZEngine\Reflection;

use PHPUnit\Framework\TestCase;
use ZEngine\HotSwap\HotSwap;

/**
 * class_alias() publishes the alias in the class table as an IS_ALIAS_PTR bucket pointing at the
 * class entry of the original class, not as the IS_PTR bucket a declaration produces. Every
 * name-based lookup must resolve such a bucket to the original class, and the operations that
 * rewrite the bucket they looked up must act on the original class's own bucket instead.
 *
 * The classes exist only at runtime (eval), so all member access goes through the native
 * reflection API to stay statically analyzable.
 */
final class ClassAliasTest extends TestCase
{
    /**
     * Declares a uniquely named class plus an alias of it, and returns both names
     *
     * @return array{class-string, class-string} [class name, alias name]
     */
    private static function declareAliasedClass(string $suffix, string $declaration): array
    {
        $className = 'ClassAliasTarget' . $suffix;
        $aliasName = 'ClassAliasAlias' . $suffix;
        eval(sprintf($declaration, $className));
        class_alias($className, $aliasName);
        if (!class_exists($className, false) || !class_exists($aliasName, false)) {
            throw new \LogicException("Declaring {$className} with its alias {$aliasName} failed");
        }

        return [$className, $aliasName];
    }

    public function testReflectionClassResolvesAnAliasToTheOriginalClass(): void
    {
        [$className, $aliasName] = self::declareAliasedClass('Final', 'final class %s {}');

        $reflection = new ReflectionClass($aliasName);
        $this->assertSame($className, $reflection->getName());

        // What a proxy generator does (swoole-bundle): un-final through whichever name it was given
        $reflection->setFinal(false);
        $this->assertFalse((new \ReflectionClass($className))->isFinal());

        $childName = $className . 'Child';
        eval("class {$childName} extends {$aliasName} {}");
        $this->assertTrue(is_subclass_of($childName, $className));
    }

    public function testReflectionMethodResolvesAnAliasToTheOriginalClass(): void
    {
        [$className, $aliasName] = self::declareAliasedClass(
            'Method',
            'class %s { final public function greet(): string { return "hello"; } }',
        );

        $method = new ReflectionMethod($aliasName, 'greet');
        $this->assertSame($className, $method->getDeclaringClass()->getName());

        $method->setFinal(false);
        $this->assertFalse((new \ReflectionMethod($className, 'greet'))->isFinal());
    }

    public function testEvictingThroughAnAliasIsRejectedAndKeepsTheClass(): void
    {
        [$className, $aliasName] = self::declareAliasedClass('Evict', 'class %s {}');

        try {
            (new ClassSpecializer())->evict($aliasName);
            $this->fail('Evicting through an alias must be refused');
        } catch (ClassSpecializationException $refusal) {
            $this->assertStringContainsString("{$aliasName} is an alias of {$className}", $refusal->getMessage());
        }

        // Deleting the alias bucket would have reported an eviction the engine never performed
        $this->assertTrue(class_exists($className, false));
        $this->assertTrue(class_exists($aliasName, false));
    }

    public function testHotSwapThroughAnAliasSwapsTheOriginalClass(): void
    {
        [$className, $aliasName] = self::declareAliasedClass(
            'Swap',
            'class %s { public function greet(): string { return "v1"; } }',
        );
        $instance = (new \ReflectionClass($className))->newInstance();

        // The source declares the class under its own name, as the file on disk would
        $delta = HotSwap::prepare($aliasName, "class {$className} { public function greet(): string { return \"v2\"; } }");
        $this->assertSame(['greet'], $delta->getChangedMethods());
        $delta->apply();

        $this->assertSame('v2', (new \ReflectionMethod($instance, 'greet'))->invoke($instance));

        // Both names still publish the same, swapped class
        $this->assertSame($className, (new \ReflectionClass($aliasName))->getName());
        $aliasInstance = (new \ReflectionClass($aliasName))->newInstance();
        $this->assertSame('v2', (new \ReflectionMethod($aliasInstance, 'greet'))->invoke($aliasInstance));
    }
}
