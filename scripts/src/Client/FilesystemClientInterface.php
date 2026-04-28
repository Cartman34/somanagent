<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Client;

/**
 * Interface for low-level filesystem operations.
 */
interface FilesystemClientInterface
{
    public function makeDirectory(string $path, bool $recursive = true): void;

    public function removePath(string $path): void;

    public function copyPath(string $source, string $target): void;

    public function writeFilePath(string $path, string $content): void;

    public function getFileContents(string $path): string;

    public function checkPathExists(string $path): bool;

    public function isDirectory(string $path): bool;

    public function isFile(string $path): bool;

    public function isLink(string $path): bool;
}
