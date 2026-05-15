<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveParentDelegatingConstructorRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;

$root = dirname(__DIR__);

return RectorConfig::configure()
    ->withPaths([
        $root.'/src',
        $root.'/tests',
        $root.'/bootstrap-alias.php',
    ])
    ->withPhpSets(php83: true)
    ->withConfiguredRule(AddOverrideAttributeToOverriddenMethodsRector::class, [
        AddOverrideAttributeToOverriddenMethodsRector::ADD_TO_INTERFACE_METHODS => true,
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true
    )
    ->withSkip([
        // Keeps public readonly statusCode alongside RuntimeException::getCode().
        RemoveParentDelegatingConstructorRector::class => [
            $root.'/src/PureCache/Ignite/IgniteCommandException.php',
        ],
    ]);
