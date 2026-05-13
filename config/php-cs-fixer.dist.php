<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$cacheDir = $root.'/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0o755, true);
}

$finder = PhpCsFixer\Finder::create()
    ->in($root)
    ->exclude('vendor')
    ->exclude('cache')
    ->exclude('php-memcached')
    ->notPath('a.php')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setCacheFile($cacheDir.'/php-cs-fixer.cache')
    ->setRiskyAllowed(true)
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP83Migration' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'array_syntax' => ['syntax' => 'short'],
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
    ])
    ->setFinder($finder);
