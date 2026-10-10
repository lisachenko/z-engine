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

use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Stub\Alias\Ancestor;
use ZEngine\Stub\Alias\AncestorDonor;
use ZEngine\Stub\Alias\Constructed;
use ZEngine\Stub\Alias\ConstructedDonor;
use ZEngine\Stub\Alias\Descendant;
use ZEngine\Stub\Alias\DescendantDonor;
use ZEngine\Stub\Alias\DescendantOverrideDonor;
use ZEngine\Stub\Alias\GrandDescendant;
use ZEngine\Stub\Alias\Greeter;
use ZEngine\Stub\Alias\GreeterDonor;
use ZEngine\Stub\Alias\Secretive;
use ZEngine\Stub\Alias\SecretiveDonor;
use ZEngine\Stub\Alias\Statics;
use ZEngine\Stub\Alias\StaticsChild;
use ZEngine\Stub\Alias\StaticsDonor;

/**
 * Child-process matrix for redefine(preserveAs:) and addMethod() overrides
 *
 * Runs the alias scenarios in the order the engine supports (a class is redefined before
 * its subclasses are loaded) and reports through the exit code:
 *   0 - every check passed and the process shut down cleanly,
 *   1 - a check failed (diagnostic on stderr),
 *   2 - the requested opcache mode is not in effect (the shared-memory branch would be
 *       silently skipped).
 *
 * Run with opcache on, the stubs are immutable shared-memory classes and every operation
 * copies them out first. Run with USE_ZEND_ALLOC=0, the engine's shutdown destroys every
 * class and function through its table destructors - the path that double-frees a
 * run-time cache published in more than one method table unless it is request memory
 * without the heap flag.
 */
require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

$expectOpcache = (int) ($argv[1] ?? '0') === 1;
$opcacheActive = function_exists('opcache_get_status') && opcache_get_status(false) !== false;
if ($expectOpcache !== $opcacheActive) {
    fwrite(STDERR, 'opcache mode mismatch: expected ' . ($expectOpcache ? 'on' : 'off') . "\n");
    exit(2);
}

$failures = 0;
$check    = static function (string $label, mixed $expected, mixed $actual) use (&$failures): void {
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
    }
};

// --- Instance method, method donor, alias ---------------------------------------------
$greeter = new ReflectionClass(Greeter::class);
$check('immutable matches the opcache mode', $expectOpcache, $greeter->isImmutable());
$greeter->getMethod('greet')->redefine((new ReflectionClass(GreeterDonor::class))->getMethod('greet'), preserveAs: 'greetOriginal');
$instance = new Greeter('a');
$check('greet dispatches through the donor body', '[hi x from a]', $instance->greet('x'));
$check('alias is private', true, (new ReflectionMethod(Greeter::class, 'greetOriginal'))->isPrivate());
$check('class is writable after the copy-out', false, (new ReflectionClass(Greeter::class))->isImmutable());

// --- Static method with late static binding, subclass loaded afterwards ----------------
(new ReflectionClass(Statics::class))->getMethod('label')->redefine((new ReflectionClass(StaticsDonor::class))->getMethod('label'), preserveAs: 'labelOriginal');
$check('static dispatch', '<label:' . Statics::class . ':s>', Statics::label('s'));
$check('static LSB through a subclass loaded later', '<label:' . StaticsChild::class . ':s>', StaticsChild::label('s'));

// --- Private method ---------------------------------------------------------------------
(new ReflectionClass(Secretive::class))->getMethod('secret')->redefine((new ReflectionClass(SecretiveDonor::class))->getMethod('secret'), preserveAs: 'secretOriginal');
$check('private dispatch', 86, (new Secretive())->reveal());

// --- Constructor --------------------------------------------------------------------------
(new ReflectionClass(Constructed::class))->getMethod('__construct')->redefine((new ReflectionClass(ConstructedDonor::class))->getMethod('__construct'), preserveAs: '__constructOriginal');
Constructed::$log = [];
$check('constructor dispatch', 'ABC', (new Constructed('abc'))->name);
$check('constructor slot', '__construct', (new ReflectionClass(Constructed::class))->getConstructor()?->getName());

// --- Inheritance chain: ancestor, then descendant, then grand-descendant ----------------
(new ReflectionClass(Ancestor::class))->getMethod('greet')->redefine((new ReflectionClass(AncestorDonor::class))->getMethod('greet'), preserveAs: 'greetOriginal');
$check('descendant through the redefined ancestor', 'child:[hi q]', (new Descendant())->greet('q'));
(new ReflectionClass(Descendant::class))->getMethod('greet')->redefine((new ReflectionClass(DescendantDonor::class))->getMethod('greet'), preserveAs: 'greetOriginal');
$check('both redefined (CHANGED alias)', '{child:[hi w]}', (new Descendant())->greet('w'));
$check('grand-descendant', '{child:[hi g]}', (new GrandDescendant())->greet('g'));

// --- Override of an inherited method, grand-descendant linked before --------------------
(new ReflectionClass(Descendant::class))->addMethod('inheritedOnly', (new ReflectionClass(DescendantOverrideDonor::class))->getMethod('inheritedOnly'));
$check('override dispatch', 1002, (new Descendant())->inheritedOnly(1));
$check('override reaches the grand-descendant linked before', 1002, (new GrandDescendant())->inheritedOnly(1));
$check('ancestor untouched', 2, (new Ancestor())->inheritedOnly(1));

// --- Hot loop: warmed inline caches, no growth --------------------------------------------
$before = memory_get_usage();
$length = 0;
for ($iteration = 0; $iteration < 3000; $iteration++) {
    $length += strlen($instance->greet('loop'));
    $length += strlen(Statics::label('l'));
    $length += strlen((new GrandDescendant())->greet('c'));
}
$check('hot loop produced output', true, $length > 0);
$check('hot loop stays memory-flat', true, memory_get_usage() - $before < 64 * 1024);

if ($failures > 0) {
    exit(1);
}
echo "redefine-alias-matrix: ok\n";
