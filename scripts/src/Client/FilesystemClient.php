<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Client;

/**
 * Concrete implementation of FilesystemClientInterface.
 */
final class FilesystemClient implements FilesystemClientInterface
{
    /**
     * @param string $path The directory path to create
     * @param bool $recursive Whether to create directories recursively (default: true)
     * @return void
     */
    public function makeDirectory(string $path, bool $recursive = true): void
    {
        if (!is_dir($path) && !mkdir($path, 0777, $recursive) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $path));
        }
    }

    /**
     * @param string $path The path to remove (file, link, or directory)
     * @return void
     */
    public function removePath(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException(sprintf('Unable to remove file/link "%s"', $path));
            }

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                if (!rmdir($item->getPathname())) {
                    throw new \RuntimeException(sprintf('Unable to remove directory "%s"', $item->getPathname()));
                }
                continue;
            }

            if (!unlink($item->getPathname())) {
                throw new \RuntimeException(sprintf('Unable to remove file "%s"', $item->getPathname()));
            }
        }

        if (!rmdir($path)) {
            throw new \RuntimeException(sprintf('Unable to remove directory "%s"', $path));
        }
    }

    /**
     * @param string $source The source path to copy
     * @param string $target The target path
     * @return void
     */
    public function copyPath(string $source, string $target): void
    {
        if (is_link($source)) {
            $linkTarget = readlink($source);
            if ($linkTarget === false || !symlink($linkTarget, $target)) {
                throw new \RuntimeException(sprintf('Unable to copy symlink "%s"', $source));
            }

            return;
        }

        if (is_file($source)) {
            if (!copy($source, $target)) {
                throw new \RuntimeException(sprintf('Unable to copy file "%s"', $source));
            }

            return;
        }

        $this->makeDirectory($target);

        $iterator = new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $this->copyPath($item->getPathname(), $target . '/' . $item->getBasename());
        }
    }

    /**
     * @param string $path The file path to write
     * @param string $content The content to write
     * @return void
     */
    public function writeFilePath(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException(sprintf('Unable to write to file "%s"', $path));
        }
    }

    /**
     * @param string $path The file path to read
     * @return string The file contents
     */
    public function getFileContents(string $path): string
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Unable to read file "%s"', $path));
        }

        return $content;
    }

    /**
     * @param string $path The path to check
     * @return bool True if the path exists (file, directory, or link)
     */
    public function checkPathExists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    /**
     * @param string $path The path to check
     * @return bool True if the path is a directory
     */
    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    /**
     * @param string $path The path to check
     * @return bool True if the path is a regular file
     */
    public function isFile(string $path): bool
    {
        return is_file($path);
    }

    /**
     * @param string $path The path to check
     * @return bool True if the path is a symbolic link
     */
    public function isLink(string $path): bool
    {
        return is_link($path);
    }
}
