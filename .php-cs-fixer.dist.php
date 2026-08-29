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

/**
 * The license header every file in the repository carries.
 *
 * It is not yet wired to the `header_comment` fixer, and cannot be until one question is
 * settled: that fixer enforces a single literal header, while the corpus carries three
 * copyright years - 2019 (89 files, the original work), 2020 (29) and 2026 (168, everything
 * added since). Whichever year the rule hardcodes, it rewrites the @copyright line of every
 * file carrying either of the other two: 118 files for 2026, 210 for 2019, 270 for 2020.
 * That is not a formatting fix, it restates when the file was written.
 *
 * Everything else the fixer needs is now in place: every file the finder sees carries the
 * header, in one identical shape (PHPDoc, right after the open tag, closed immediately
 * before `declare`), so once the year question is answered the rule below is a one-liner:
 *
 *     'header_comment' => [
 *         'header'       => $header,
 *         'comment_type' => 'PHPDoc',
 *         'location'     => 'after_open',
 *         'separate'     => 'top',
 *     ],
 *
 * (verified against the corpus: with that configuration the only diff the fixer produces is
 * the @copyright year itself).
 */
$header = <<<'HEADER'
Z-Engine framework

@copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>

This source file is subject to the license that is bundled
with this source code in the file LICENSE.

HEADER;

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools'])
    ->name('*.php')
    // The root-level scripts live outside every directory above, but they are shipped
    // code (bootstrap.php runs in every consumer process) and this config is code too
    ->append([
        __DIR__ . '/bootstrap.php',
        __DIR__ . '/preload.php',
        __FILE__,
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    // This branch targets PHP 8.6, which php-cs-fixer does not list as
    // supported while it is pre-release; the codebase uses no 8.6-only syntax,
    // so the fixer output stays stable. Drop once the fixer supports 8.6.
    ->setUnsupportedPhpVersionAllowed(true)
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
