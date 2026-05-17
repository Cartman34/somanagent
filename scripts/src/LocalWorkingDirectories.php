<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script;

use SoManAgent\Script\Client\FilesystemClientInterface;

/**
 * Ensures local-only working directories exist with tracked keep files.
 */
final class LocalWorkingDirectories
{
    /** @var list<string> */
    private const DIRECTORIES = [
        'local/tmp',
        'local/tests',
    ];

    /**
     * @param string $projectRoot Project or worktree root
     */
    public static function ensure(string $projectRoot, FilesystemClientInterface $fs): void
    {
        foreach (self::DIRECTORIES as $relativePath) {
            $directory = rtrim($projectRoot, '/') . '/' . $relativePath;
            if (!$fs->isDirectory($directory)) {
                $fs->makeDirectory($directory);
            }

            $keepFile = $directory . '/.gitkeep';
            if (!$fs->isFile($keepFile)) {
                $fs->writeFilePath($keepFile, '');
            }
        }
    }
}
