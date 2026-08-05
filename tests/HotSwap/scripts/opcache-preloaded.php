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

/*
 * Subprocess probe for OpcacheSupportMatrixTest: runs with opcache.preload, so the
 * template class is ZEND_ACC_PRELOADED on top of ZEND_ACC_IMMUTABLE. A preloaded class
 * keeps its class-table bucket across every request of the worker process, while a
 * copy-out lives in request memory - so the mutation APIs must refuse it with a typed
 * SharedMemoryException instead of leaving a dangling bucket behind.
 *
 * Exit codes: 0 success, 1 assertion failure, 2 setup problem (no preloaded template).
 */

use ZEngine\Core;
use ZEngine\OpCache\SharedMemoryException;
use ZEngine\Reflection\ReflectionClass;

require __DIR__ . '/../../../vendor/autoload.php';

Core::init();

$templateName = 'ZEngine\StubShm\ImmutableTemplate';
if (!class_exists($templateName)) {
    fwrite(STDERR, "the preloaded template class is missing\n");
    exit(2);
}
$templateClass = new ReflectionClass($templateName);
if (!$templateClass->isImmutable()) {
    fwrite(STDERR, "the template class is not immutable - preload did not publish it from shared memory\n");
    exit(2);
}
if (($templateClass->getFlags() & Core::engineConstant('ZEND_ACC_PRELOADED')) === 0) {
    fwrite(STDERR, "the template class is not marked as preloaded\n");
    exit(2);
}

try {
    $templateClass->addMethod('injected', function (): string {
        return 'nope';
    });
    fwrite(STDERR, "addMethod on a preloaded class was not rejected\n");
    exit(1);
} catch (SharedMemoryException $exception) {
    if (!str_contains($exception->getMessage(), 'preloaded')) {
        fwrite(STDERR, "the rejection does not name the preloaded storage: {$exception->getMessage()}\n");
        exit(1);
    }
}

// The refusal must leave the class exactly as it was - still published from shared
// memory, still immutable, still dispatching its own methods
$publishedClass = new ReflectionClass($templateName);
if (!$publishedClass->isImmutable()) {
    fwrite(STDERR, "the preloaded class entry was replaced despite the rejection\n");
    exit(1);
}
$instantiate = static function (string $className): object {
    return new $className(7);
};
$callMethod = static function (object $target, string $methodName): string {
    $result = $target->{$methodName}();

    return is_string($result) ? $result : '';
};
if ($callMethod($instantiate($templateName), 'describe') !== $templateName . ':7') {
    fwrite(STDERR, "the preloaded class stopped dispatching its own methods\n");
    exit(1);
}

echo "preloaded-rejected: ok\n";
