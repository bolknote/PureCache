<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

$root = dirname(__DIR__);

return RectorConfig::configure()
    ->withPaths([
        $root.'/src',
        $root.'/tests',
        $root.'/bootstrap-alias.php',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true
    )
    ->withSkip([
        Rector\TypeDeclaration\Rector\ClassMethod\StrictArrayParamDimFetchRector::class,
        Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector::class,
    ]);
