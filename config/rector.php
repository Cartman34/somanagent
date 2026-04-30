<?php
/**
 * Rector configuration for the entire SoManAgent project.
 *
 * Covers both the backend (Symfony app) and the scripts tooling.
 * The binary lives in scripts/vendor so that a single
 * `php scripts/scripts-install.php` is enough to get automated fixes running,
 * without requiring the backend Docker environment.
 *
 * To run: php scripts/rector.php
 * To restrict scope: php scripts/rector.php --backend  |  php scripts/rector.php --scripts
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/../backend/src',
        __DIR__ . '/../backend/tests',
        __DIR__ . '/../scripts/src',
    ])
    ->withPhpStanConfigs([__DIR__ . '/phpstan.neon'])
    ->withSets([
        SetList::TYPE_DECLARATION,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
    ]);
