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
use ZEngine\OpCache\SharedMemoryException;
use ZEngine\Reflection\FunctionBodySwap;
use ZEngine\Reflection\FunctionLikeInterface;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionFunction;
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
use ZEngine\Stub\Alias\MatrixChangedDonor;
use ZEngine\Stub\Alias\MatrixChangedLeaf;
use ZEngine\Stub\Alias\MatrixChangedLeafSub;
use ZEngine\Stub\Alias\MatrixChangedMid;
use ZEngine\Stub\Alias\MatrixCloneBase;
use ZEngine\Stub\Alias\MatrixCloneChild;
use ZEngine\Stub\Alias\MatrixCloneDonorV1;
use ZEngine\Stub\Alias\MatrixCloneDonorV2;
use ZEngine\Stub\Alias\MatrixEarlyBase;
use ZEngine\Stub\Alias\MatrixEarlyChild;
use ZEngine\Stub\Alias\MatrixEarlyDonor;
use ZEngine\Stub\Alias\MatrixEarlyGrandChild;
use ZEngine\Stub\Alias\MatrixFakeClosureDonorV1;
use ZEngine\Stub\Alias\MatrixFakeClosureDonorV2;
use ZEngine\Stub\Alias\MatrixFakeClosureTarget;
use ZEngine\Stub\Alias\MatrixStaticsCounter;
use ZEngine\Stub\Alias\MatrixStaticsCounterDonor;
use ZEngine\Stub\Alias\Secretive;
use ZEngine\Stub\Alias\SecretiveDonor;
use ZEngine\Stub\Alias\Statics;
use ZEngine\Stub\Alias\StaticsChild;
use ZEngine\Stub\Alias\StaticsDonor;
use ZEngine\Type\ClosureEntry;

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
// Address of the run-time cache block a user function reads its inline caches from (0 when none)
$cacheAddress = static function (FunctionLikeInterface $function): int {
    $cache = $function->getOpArrayPointer()->run_time_cache__ptr;

    return $cache === null ? 0 : Core::addressOf($cache);
};
// Whether the function owns its run-time cache (ZEND_ACC_HEAP_RT_CACHE: destroy_op_array frees it)
$hasHeapCache = static function (FunctionLikeInterface $function): bool {
    return ($function->getCommonPointer()->fn_flags & Core::ZEND_ACC_HEAP_RT_CACHE) !== 0;
};
// Pointer-level view of the zend_function embedded in a closure object (the closure must stay alive)
$functionOfClosure = static function (\Closure $closure): ReflectionFunction {
    $closureEntry = new ClosureEntry($closure);

    return ReflectionFunction::fromCData(Core::addr($closureEntry->getRawFunction()));
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

// --- Fake closures over a redefined method, then a second redefinition ----------------------
// zend_create_closure_ex copies the entry struct into the closure, cache pointer included, when
// the scope matches and the cache is not heap-flagged: the three fake-closure forms share the
// cache minted by swap #1. Swap #2 releases that block - after handing each closure a private
// heap copy (ZEND_ACC_HEAP_RT_CACHE) that destroy_op_array frees with the closure. Before the fix
// the closures read, and at their destruction freed, the released block.
$fakeEntry = (new ReflectionClass(MatrixFakeClosureTarget::class))->getMethod('greet');
$fakeEntry->redefine((new ReflectionClass(MatrixFakeClosureDonorV1::class))->getMethod('greet'));
$fakeEntry      = (new ReflectionClass(MatrixFakeClosureTarget::class))->getMethod('greet');
$fakeFirstCache = $cacheAddress($fakeEntry);
$check('swap #1 minted a run-time cache for the entry', true, $fakeFirstCache !== 0);
$fakeTarget   = new MatrixFakeClosureTarget('t');
$fakeClosures = [
    'first-class callable'         => $fakeTarget->greet(...),
    'Closure::fromCallable'        => \Closure::fromCallable([$fakeTarget, 'greet']),
    'ReflectionMethod::getClosure' => (new \ReflectionMethod(MatrixFakeClosureTarget::class, 'greet'))->getClosure($fakeTarget),
];
foreach ($fakeClosures as $kind => $fakeClosure) {
    $closureFunction = $functionOfClosure($fakeClosure);
    $check("{$kind} shares the cache minted by swap #1", $fakeFirstCache, $cacheAddress($closureFunction));
    $check("{$kind} carries no heap flag while sharing", false, $hasHeapCache($closureFunction));
    $check("{$kind} dispatches the swap #1 body and warms the shared block", 'v1:A from t..', $fakeClosure('a'));
}
$fakeEntry->redefine((new ReflectionClass(MatrixFakeClosureDonorV2::class))->getMethod('greet'));
$fakeSecondCache = $cacheAddress($fakeEntry);
$check('swap #2 installed a fresh cache on the entry', true, $fakeSecondCache !== 0 && $fakeSecondCache !== $fakeFirstCache);
$check('direct dispatch after swap #2', 'v2:B from t..', $fakeTarget->greet('b'));
foreach ($fakeClosures as $kind => $fakeClosure) {
    $closureFunction = $functionOfClosure($fakeClosure);
    $closureCache    = $cacheAddress($closureFunction);
    $check("{$kind} left the released block", true, $closureCache !== 0 && $closureCache !== $fakeFirstCache);
    $check("{$kind} does not share the current cache of the entry", true, $closureCache !== $fakeSecondCache);
    $check("{$kind} owns a heap copy the engine frees with the closure", true, $hasHeapCache($closureFunction));
    $check("{$kind} still dispatches the swap #1 body", 'v1:C from t..', $fakeClosure('c'));
}

// --- Interface clone linked between two redefinitions ----------------------------------------
// A subclass implementing an interface that declares the inherited method gets a struct copy of
// the entry (do_inheritance_check_on_method), cache pointer included, instead of the entry
// pointer. The second swap must swap the clone in place and give it a cache of its own before
// the shared block is released; before the fix the subclass kept the previous body over freed memory.
$cloneEntry = (new ReflectionClass(MatrixCloneBase::class))->getMethod('greet');
$cloneEntry->redefine((new ReflectionClass(MatrixCloneDonorV1::class))->getMethod('greet'));
$cloneEntry      = (new ReflectionClass(MatrixCloneBase::class))->getMethod('greet');
$cloneFirstCache = $cacheAddress($cloneEntry);
// Autoloaded now, i.e. linked against the redefined base
$cloneChild = new MatrixCloneChild('c');
$check('the subclass table holds no share of the entry pointer', 1, count(FunctionBodySwap::publishedShareHolders($cloneEntry)));
$cloneHolders = FunctionBodySwap::cloneHolders($cloneEntry);
$check('the subclass holds one engine clone of the entry', 1, count($cloneHolders));
$cloneAddress = 0;
foreach ($cloneHolders as [$cloneHolder, $clone]) {
    $cloneAddress = $clone->getAddress();
    $check('clone holder', MatrixCloneChild::class, $cloneHolder->getName());
    $check('the clone is a struct copy, not the entry', true, $cloneAddress !== $cloneEntry->getAddress());
    $check('the clone shares the cache minted by swap #1', $cloneFirstCache, $cacheAddress($clone));
    $check('the clone carries no heap flag', false, $hasHeapCache($clone));
}
$check('the clone dispatches the swap #1 body and warms the shared block', 'v1:A from c..', $cloneChild->greet('a'));
$cloneEntry->redefine((new ReflectionClass(MatrixCloneDonorV2::class))->getMethod('greet'));
$cloneSecondCache = $cacheAddress($cloneEntry);
$check('swap #2 installed a fresh cache on the entry', true, $cloneSecondCache !== 0 && $cloneSecondCache !== $cloneFirstCache);
$check('the base dispatches the swap #2 body', 'v2:B from b..', (new MatrixCloneBase('b'))->greet('b'));
$check('the subclass dispatches the swap #2 body through its clone', 'v2:B from c..', $cloneChild->greet('b'));
$cloneHolders = FunctionBodySwap::cloneHolders($cloneEntry);
$check('the clone is still the only engine copy', 1, count($cloneHolders));
foreach ($cloneHolders as [, $clone]) {
    $cloneCache = $cacheAddress($clone);
    $check('the clone was swapped in place', $cloneAddress, $clone->getAddress());
    $check('the clone left the released block', true, $cloneCache !== 0 && $cloneCache !== $cloneFirstCache);
    $check('the clone has a minted cache of its own', true, $cloneCache !== $cloneSecondCache);
    $check('the clone cache is request memory the engine never frees itself', false, $hasHeapCache($clone));
}

// --- Closure donor with statics, alias from a method donor, swap back, removal -----------------
// The closure donor's statics defaults table is guarded by the body refcount on PHP 8.5 (no
// duplicate is minted): the entry shares it after swap #1 and its live table materializes on
// the first call. The alias adopting that body shares the defaults too and takes over the live
// table; the entry swapped back from the alias starts afresh; removeMethods() releases what the
// alias owned.
$counterEntry = (new ReflectionClass(MatrixStaticsCounter::class))->getMethod('counter');
$counterEntry->redefine(function (): int {
    /** @var int $n */
    static $n = 10;

    return ++$n;
});
$counterEntry        = (new ReflectionClass(MatrixStaticsCounter::class))->getMethod('counter');
$counterEntryAddress = $counterEntry->getAddress();
$counterInstance     = new MatrixStaticsCounter();
$check('closure body counts from its own default', 11, $counterInstance->counter());
$counterDefaults = $counterEntry->getOpArrayPointer()->static_variables;
assert($counterDefaults !== null);
$counterDefaultsAddress = Core::addressOf($counterDefaults);
$counterLiveTable       = $counterEntry->getLiveStaticsPointer();
assert($counterLiveTable !== null);
$counterLiveAddress = Core::addressOf($counterLiveTable);

$counterEntry->redefine((new ReflectionClass(MatrixStaticsCounterDonor::class))->getMethod('counter'), preserveAs: 'counterOriginal');
$counterAlias         = (new ReflectionClass(MatrixStaticsCounter::class))->getMethod('counterOriginal');
$counterAliasAddress  = $counterAlias->getAddress();
$counterAliasDefaults = $counterAlias->getOpArrayPointer()->static_variables;
$check('the alias carries a defaults table', true, $counterAliasDefaults !== null);
$check('the alias shares the refcount-guarded defaults table of the body it preserves', $counterDefaultsAddress, $counterAliasDefaults === null ? 0 : Core::addressOf($counterAliasDefaults));
$check('the alias is a struct of its own', true, $counterAliasAddress !== $counterEntryAddress);
$check('the entry executes a body without statics', null, $counterEntry->getOpArrayPointer()->static_variables);
$counterAliasLive = $counterAlias->getLiveStaticsPointer();
$check('the alias took over the live statics table', $counterLiveAddress, $counterAliasLive === null ? 0 : Core::addressOf($counterAliasLive));
$check('the entry starts afresh', null, $counterEntry->getLiveStaticsPointer());
$check('the alias keeps counting the preserved state', 112, $counterInstance->counter());
$check('the alias keeps counting', 113, $counterInstance->counter());

// Swap the preserved body back in from the alias: a method donor sharing the defaults table
$counterEntry->redefine($counterAlias);
$counterRestoredDefaults = $counterEntry->getOpArrayPointer()->static_variables;
$check('the restored body shares the defaults table of the preserved body', $counterDefaultsAddress, $counterRestoredDefaults === null ? 0 : Core::addressOf($counterRestoredDefaults));
$check('the restored body starts from the defaults', 11, $counterInstance->counter());
$check('the restored body counts on', 12, $counterInstance->counter());
(new ReflectionClass(MatrixStaticsCounter::class))->removeMethods('counterOriginal');
$check('the alias is unpublished', false, (new ReflectionClass(MatrixStaticsCounter::class))->hasMethod('counterOriginal'));
$check('the entry is unaffected by the removal', 13, $counterInstance->counter());

// --- addMethod() override below a redeclared ancestor-private method (ZEND_ACC_CHANGED) --------
// MatrixChangedMid::m() redeclares the private MatrixChangedRoot::m() and carries ZEND_ACC_CHANGED,
// which MatrixChangedLeaf inherits. The override published on the leaf must carry the bit too
// (do_inheritance_check_on_method propagates it), or `$this->m()` inside MatrixChangedRoot::viaRoot()
// resolves the public override instead of the root's private method on leaf instances.
$leafClass     = new ReflectionClass(MatrixChangedLeaf::class);
$inheritedLeaf = $leafClass->getMethod('m');
$check('the inherited method carries CHANGED', Core::ZEND_ACC_CHANGED, $inheritedLeaf->getCommonPointer()->fn_flags & Core::ZEND_ACC_CHANGED);
$check('the inherited method is the middle declaration', MatrixChangedMid::class, (new \ReflectionMethod(MatrixChangedLeaf::class, 'm'))->getDeclaringClass()->getName());
$override      = $leafClass->addMethod('m', (new ReflectionClass(MatrixChangedDonor::class))->getMethod('m'));
$overrideFlags = $override->getCommonPointer()->fn_flags;
$check('the override inherited CHANGED from the replaced entry', Core::ZEND_ACC_CHANGED, $overrideFlags & Core::ZEND_ACC_CHANGED);
$check('the override stays public', 0, $overrideFlags & (Core::ZEND_ACC_PRIVATE | Core::ZEND_ACC_PROTECTED));
$check('the donor itself carries no CHANGED bit', 0, (new ReflectionClass(MatrixChangedDonor::class))->getMethod('m')->getCommonPointer()->fn_flags & Core::ZEND_ACC_CHANGED);
$check('the override is the own entry of the leaf', $override->getAddress(), (new ReflectionClass(MatrixChangedLeaf::class))->getMethod('m')->getAddress());
$check('the root code still reaches its private method on a leaf instance', 'root', (new MatrixChangedLeaf())->viaRoot());
$check('the override dispatches from the outside', 'leaf', (new MatrixChangedLeaf())->m());
$check('the middle class is untouched: root code', 'root', (new MatrixChangedMid())->viaRoot());
$check('the middle class is untouched: own method', 'mid', (new MatrixChangedMid())->m());
$check('the override keeps the prototype chain', MatrixChangedMid::class, (new \ReflectionMethod(MatrixChangedLeaf::class, 'm'))->getPrototype()->getDeclaringClass()->getName());
// Autoloaded now: inherits the published pointer, bit included
$leafSub = new MatrixChangedLeafSub();
$check('a subclass linked after inherits the override pointer', $override->getAddress(), (new ReflectionClass(MatrixChangedLeafSub::class))->getMethod('m')->getAddress());
$check('the root code reaches its private method on the subclass too', 'root', $leafSub->viaRoot());
$check('the override dispatches on the subclass', 'leaf', $leafSub->m());

// --- Overriding subclass linked BEFORE a preserving redefinition --------------------------------
// MatrixEarlyChild overrides handle() and reaches the preserved body through parent::, so the
// alias has to exist in its table (and in the leaf's below it) or the preserved body fails on
// child instances. A subclass linked against a shared-memory entry keeps the shared parent after
// the copy-out and could never see the alias: the publication is refused before anything changes.
$earlyGrandChild = new MatrixEarlyGrandChild();
$check('linked before: the override dispatches through parent::', 'child:base', $earlyGrandChild->handle());
$earlyBase    = new ReflectionClass(MatrixEarlyBase::class);
$earlyRefusal = null;
try {
    $earlyBase->getMethod('handle')->redefine((new ReflectionClass(MatrixEarlyDonor::class))->getMethod('handle'), preserveAs: 'handleOriginal');
} catch (SharedMemoryException $exception) {
    $earlyRefusal = $exception;
}
if ($expectOpcache) {
    $check('refused for the shared-memory class with linked subclasses', true, $earlyRefusal !== null);
    $check(
        'the refusal names the subclass linked against the shared entry',
        1,
        preg_match('/subclass .*MatrixEarly(Child|GrandChild) was linked against the shared entry/', $earlyRefusal instanceof SharedMemoryException ? $earlyRefusal->getMessage() : ''),
    );
    $check('nothing was modified: no alias published', false, (new ReflectionClass(MatrixEarlyBase::class))->hasMethod('handleOriginal'));
    $check('nothing was modified: the class is still shared', true, (new ReflectionClass(MatrixEarlyBase::class))->isImmutable());
    $check('nothing was modified: previous dispatch', 'child:base', (new MatrixEarlyChild())->handle());
    $check('nothing was modified: base dispatch', 'base', (new MatrixEarlyBase())->handle());
} else {
    $check('the alias was published', null, $earlyRefusal);
    $earlyAlias = (new ReflectionClass(MatrixEarlyBase::class))->getMethod('handleOriginal');
    foreach ([MatrixEarlyChild::class, MatrixEarlyGrandChild::class] as $earlySubclass) {
        $check("{$earlySubclass} publishes the alias pointer", $earlyAlias->getAddress(), (new ReflectionClass($earlySubclass))->getMethod('handleOriginal')->getAddress());
    }
    $check('the alias is private', true, (new \ReflectionMethod(MatrixEarlyBase::class, 'handleOriginal'))->isPrivate());
    $check('three tables share the alias (one body share each)', 3, count(FunctionBodySwap::publishedShareHolders($earlyAlias)));
    $check('the alias cache is request memory shared by the three tables', false, $hasHeapCache($earlyAlias));
    $check('base dispatch through the donor body', '[base]', (new MatrixEarlyBase())->handle());
    $check('the overriding subclass reaches the alias through parent::', 'child:[base]', (new MatrixEarlyChild())->handle());
    $check('the leaf below it too', 'child:[base]', $earlyGrandChild->handle());
}

// --- Hot loop: warmed inline caches, no growth --------------------------------------------
$before = memory_get_usage();
$length = 0;
for ($iteration = 0; $iteration < 3000; $iteration++) {
    $length += strlen($instance->greet('loop'));
    $length += strlen(Statics::label('l'));
    $length += strlen((new GrandDescendant())->greet('c'));
    foreach ($fakeClosures as $fakeClosure) {
        $fakeResult = $fakeClosure('loop');
        assert(is_string($fakeResult));
        $length += strlen($fakeResult);
    }
    $length += strlen($fakeTarget->greet('loop'));
    $length += strlen($cloneChild->greet('loop'));
    $length += $counterInstance->counter() > 0 ? 1 : 0;
    $length += strlen($leafSub->viaRoot()) + strlen($leafSub->m());
    $length += strlen($earlyGrandChild->handle());
}
$check('hot loop produced output', true, $length > 0);
$check('hot loop stays memory-flat', true, memory_get_usage() - $before < 64 * 1024);

if ($failures > 0) {
    exit(1);
}
echo "redefine-alias-matrix: ok\n";
