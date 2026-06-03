<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Client;

/**
 * Interface for low-level filesystem operations.
 */
interface FilesystemClientInterface
{
    /**
     * Creates a directory at the given path, optionally creating intermediate directories.
     */
    public function makeDirectory(string $path, bool $recursive = true): void;

    /**
     * Removes a file or directory tree at the given path.
     */
    public function removePath(string $path): void;

    /**
     * Copies a file or directory tree from source to target.
     */
    public function copyPath(string $source, string $target): void;

    /**
     * Writes content to the file at the given path, creating it if necessary.
     */
    public function writeFilePath(string $path, string $content): void;

    /**
     * Returns the contents of the file at the given path.
     */
    public function getFileContents(string $path): string;

    /**
     * Returns true when a file or directory exists at the given path.
     */
    public function checkPathExists(string $path): bool;

    /**
     * Returns true when the given path points to a directory.
     */
    public function isDirectory(string $path): bool;

    /**
     * Returns true when the given path points to a regular file.
     */
    public function isFile(string $path): bool;

    /**
     * Returns true when the given path points to a symbolic link.
     */
    public function isLink(string $path): bool;
}
