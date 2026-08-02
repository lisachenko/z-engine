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

/*
 * Subprocess probe for ClassSpecializerShmTest: runs with opcache.enable_cli=1 and
 * opcache.preload=specializationShmPreload.php, so ZEngine\StubShm\ImmutableTemplate is
 * a genuinely ZEND_ACC_IMMUTABLE (shared-memory) class entry. The probe specializes it,
 * exercises the copy and then verifies the shared-memory template was left untouched.
 *
 * Exit codes: 0 success, 1 assertion failure, 2 template missing, 3 template not
 * immutable (broken preload setup - the parent test FAILS on this, it never skips).
 */

declare(strict_types=1);

namespace ZEngine\StubShm;

use FFI\CData;
use ReflectionProperty;
use TypeError;
use ZEngine\Core;
use ZEngine\Reflection\ClassSpecializer;
use ZEngine\Reflection\TypeSubstitutionMap;
use ZEngine\Type\StringEntry;

require __DIR__ . '/../../vendor/autoload.php';

Core::init();

function probeFail(string $message, int $exitCode = 1): never
{
    echo "PROBE FAIL: {$message}\n";
    exit($exitCode);
}

/**
 * Property write the engine (not the analyser) validates: the substituted types of the
 * runtime-generated copy cannot be expressed statically
 */
function probeWrite(object $target, string $property, mixed $value): void
{
    $target->{$property} = $value;
}

$templateName = ImmutableTemplate::class;
$templateItem = Core::$executor->classTable->find(strtolower($templateName));
if ($templateItem === null) {
    probeFail('TEMPLATE-MISSING: the preload fixture did not declare the template', 2);
}
$sourceEntry = $templateItem->getRawClass();
$flagsBefore = $sourceEntry->ce_flags;
if (!is_int($flagsBefore) || ($flagsBefore & Core::ZEND_ACC_IMMUTABLE) === 0) {
    probeFail('TEMPLATE-NOT-IMMUTABLE: preload did not produce a shared-memory class', 3);
}

// Snapshot the shared-memory template state that must survive the specialization
$rawTemplateName = $sourceEntry->name;
$templateMethods = $sourceEntry->function_table;
assert($rawTemplateName instanceof CData && $templateMethods instanceof CData);
$nameBefore      = StringEntry::fromCData($rawTemplateName)->getStringValue();
$methodsBefore   = $templateMethods->nNumOfElements;
$typeBefore      = (string) (new ReflectionProperty($templateName, 'value'))->getType();
$instancesBefore = ImmutableTemplate::$instances;

// --- Specialize the immutable template (full copy-out of the SHM class entry) --------
$copyName    = 'ZEngine\StubShm\IntCopy';
$specialized = (new ClassSpecializer())->specialize($templateName, $copyName, new TypeSubstitutionMap([
    'ZEngine\StubShm\TProbePlaceholder' => 'int',
]));
// Read the runtime name back through the engine so nothing below is constant-folded
$runtimeCopyName = $specialized->getName();
if ($runtimeCopyName !== $copyName) {
    probeFail('specialized class name mismatch');
}

// (a) Instantiation, method dispatch, late static binding and substituted-type
//     enforcement on the copy
$copy = new IntCopy(3);
$copy->setValue(41);
if ($copy->getValue() !== 41) {
    probeFail('substituted setter/getter did not round-trip');
}
if ($copy->describe() !== $runtimeCopyName . ':3') {
    probeFail('method dispatch/static:: resolution failed on the copy');
}
if (IntCopy::whoAmI() !== $runtimeCopyName) {
    probeFail('late static binding resolved to the wrong class');
}
if (constant($runtimeCopyName . '::TEMPLATE_CONST') !== 'template') {
    probeFail('class constant was not carried over');
}
if (IntCopy::$instances !== 1 || ImmutableTemplate::$instances !== $instancesBefore) {
    probeFail('static properties are not independent');
}
$copyType = (string) (new ReflectionProperty($runtimeCopyName, 'value'))->getType();
if ($copyType !== 'int') {
    probeFail("copy property type is {$copyType}, expected int");
}
try {
    probeWrite($copy, 'value', 'not an int');
    probeFail('substituted property type is not enforced on the copy');
} catch (TypeError) {
    // expected: the copy carries the substituted int type
}

// (b) The shared-memory template is untouched: flags (still IMMUTABLE), name, method
//     table and property type all read back unchanged from the source class entry
if ($sourceEntry->ce_flags !== $flagsBefore) {
    probeFail('template ce_flags changed');
}
$rawNameAfter = $sourceEntry->name;
assert($rawNameAfter instanceof CData);
if (StringEntry::fromCData($rawNameAfter)->getStringValue() !== $nameBefore) {
    probeFail('template class name changed');
}
if ($templateMethods->nNumOfElements !== $methodsBefore) {
    probeFail('template method table changed');
}
if ((string) (new ReflectionProperty($templateName, 'value'))->getType() !== $typeBefore) {
    probeFail('template property type changed');
}
$original = new ImmutableTemplate(9);
if ($original->describe() !== $templateName . ':9') {
    probeFail('template method dispatch broken after specialization');
}
try {
    probeWrite($original, 'value', 42);
    probeFail('template accepted a value for the placeholder-typed property');
} catch (TypeError) {
    // expected: the template still carries the unresolvable placeholder type
}

// (c) Reaching this point with exit code 0 proves the request shuts down cleanly
echo "SHM PROBE OK\n";
