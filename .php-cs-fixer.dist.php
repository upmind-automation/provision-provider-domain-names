<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src');

return (new PhpCsFixer\Config())
    ->setFinder($finder)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'concat_space' => ['spacing' => 'one'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'no_extra_blank_lines' => [
            'tokens' => [
                'attribute',
                'break',
                'case',
                // 'continue',
                'curly_brace_block',
                'default',
                'extra',
                'parenthesis_brace_block',
                // 'return',
                'square_brace_block',
                'switch',
                // 'throw',
                'use',
                'use_trait',
            ],
        ],
    ]);
