<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/api',
        __DIR__ . '/config',
        __DIR__ . '/src',
    ])
    // Automatically sets PHP version based on composer.json
    ->withPhpSets()
    // Recommended sets for modernizing code
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::EARLY_RETURN,
    ]);
