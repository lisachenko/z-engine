<?php

declare(strict_types=1);

$header = <<<'HEADER'
Z-Engine framework

@copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>

This source file is subject to the license that is bundled
with this source code in the file LICENSE.
HEADER;

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0'             => true,
        'declare_strict_types'   => true,
        'ordered_imports'        => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'      => true,
        'single_quote'           => true,
        'array_syntax'           => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'align_single_space_minimal'],
    ])
    ->setFinder($finder);
