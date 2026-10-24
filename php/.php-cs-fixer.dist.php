<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/templates', __DIR__ . '/public', __DIR__ . '/scripts', __DIR__ . '/tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'control_structure_braces' => true,
        'no_multiple_statements_per_line' => true,
        'concat_space' => ['spacing' => 'one'],
        'whitespace_after_comma_in_array' => true,
        'blank_line_before_statement' => ['statements' => ['return']],
    ])
    ->setRiskyAllowed(false)
    ->setUsingCache(false)
    ->setFinder($finder);
