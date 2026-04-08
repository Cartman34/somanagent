<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script;

/**
 * Provides the common filesystem and shell helpers used by CLI auth sync managers.
 */
final class AuthSyncSupport
{
    private const HASH_ALGO = 'sha256';

    /**
     * Builds the helper around the shared script application and console instances.
     */
    public function __construct(
        private readonly Application $app,
        private readonly Console $console,
    ) {}

    /**
     * Asks for an explicit confirmation before overwriting the Docker-side auth copy.
     */
    public function confirmOverwrite(string $message): void
    {
        $this->console->warn($message);
        $this->console->warn('Type "yes" to continue:');

        $confirmation = trim((string) fgets(STDIN));
        if ($confirmation !== 'yes') {
            throw new \RuntimeException('Aborted.');
        }
    }

    /**
     * Runs a shell command and fails with a contextual message when it exits unsuccessfully.
     */
    public function runRequiredCommand(string $command, string $errorPrefix): void
    {
        $exitCode = $this->app->runCommand($command);
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf('%s (exit %d).', $errorPrefix, $exitCode));
        }
    }

    /**
     * Runs a shell command, prints its output, and reports whether it succeeded without aborting the status report.
     */
    public function printCommandStatus(string $command): void
    {
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        foreach ($output as $line) {
            $this->console->line($line);
        }

        if ($exitCode === 0) {
            $this->console->ok('Command completed successfully.');
            return;
        }

        $this->console->warn(sprintf('Command exited with status %d.', $exitCode));
    }

    /**
     * Returns a concise human-readable state for a file or directory path.
     */
    public function describePathState(string $path): string
    {
        if (is_dir($path)) {
            return sprintf('%s [dir, mtime=%s]', $path, date(\DateTimeInterface::ATOM, filemtime($path) ?: time()));
        }

        if (is_file($path)) {
            return sprintf('%s [file, %d bytes, mtime=%s]', $path, filesize($path) ?: 0, date(\DateTimeInterface::ATOM, filemtime($path) ?: time()));
        }

        return sprintf('%s [missing]', $path);
    }

    /**
     * Throws a descriptive error when any entry in the shared directory tree is not accessible by the current user.
     *
     * This typically happens when Docker created the directory as root before the host-side scripts
     * had a chance to create it themselves (e.g. after a fresh clone without running setup.php first).
     * The thrown message includes the exact fix command so the user can recover immediately.
     */
    public function assertSharedDirectoryAccessible(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $problematic = [];

        if (!is_readable($path) || !is_writable($path)) {
            $problematic[] = $path;
        }

        if ($problematic === []) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST,
                );

                foreach ($iterator as $item) {
                    if (!is_readable($item->getPathname())) {
                        $problematic[] = $item->getPathname();
                    }
                }
            } catch (\Exception) {
                // The directory itself was unreadable — already captured above.
            }
        }

        if ($problematic === []) {
            return;
        }

        $sample = array_slice($problematic, 0, 3);

        throw new \RuntimeException(sprintf(
            "%d path(s) in %s are not accessible by the current user.\n" .
            "  This happens when Docker creates mount directories as root before setup.php runs.\n\n" .
            "  Affected path(s):\n    %s\n\n" .
            "  Fix:\n    sudo chown -R \$(whoami): %s\n\n" .
            "  Then re-run this command.",
            count($problematic),
            $path,
            implode("\n    ", $sample),
            $path,
        ));
    }

    /**
     * Creates a directory recursively when it does not already exist.
     */
    public function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Failed to create directory: %s', $path));
        }
    }

    /**
     * Removes a file or directory tree recursively.
     */
    public function removePath(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException(sprintf('Failed to remove file: %s', $path));
            }
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            throw new \RuntimeException(sprintf('Failed to read directory: %s', $path));
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->removePath($path . '/' . $item);
        }

        if (!rmdir($path)) {
            throw new \RuntimeException(sprintf('Failed to remove directory: %s', $path));
        }
    }

    /**
     * Removes every child entry from a directory while preserving the directory itself.
     */
    public function clearDirectoryContents(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            throw new \RuntimeException(sprintf('Failed to read directory: %s', $path));
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->removePath($path . '/' . $item);
        }
    }

    /**
     * Copies a directory tree recursively.
     */
    public function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new \RuntimeException(sprintf('Directory not found: %s', $source));
        }

        $this->ensureDirectory($destination);

        $items = scandir($source);
        if ($items === false) {
            throw new \RuntimeException(sprintf('Failed to read directory: %s', $source));
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $sourcePath = $source . '/' . $item;
            $destinationPath = $destination . '/' . $item;

            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $destinationPath);
                continue;
            }

            $this->copyFile($sourcePath, $destinationPath);
        }
    }

    /**
     * Copies a file while creating its destination directory when needed.
     */
    public function copyFile(string $source, string $destination): void
    {
        $this->ensureDirectory(dirname($destination));

        if (!copy($source, $destination)) {
            throw new \RuntimeException(sprintf('Failed to copy file from %s to %s', $source, $destination));
        }

        @chmod($destination, 0666);
    }

    /**
     * Grants world-readable and world-writable permissions on all files and directories under
     * the given path so that the www-data PHP-FPM process can both read and refresh the auth
     * state at runtime (e.g. OAuth token rotation).
     */
    public function openSharedReadPermissions(string $path): void
    {
        if (is_file($path)) {
            chmod($path, 0666);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        chmod($path, 0777);

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->openSharedReadPermissions($path . '/' . $item);
        }
    }

    /**
     * Builds a stable hash of a directory tree contents and relative paths.
     */
    public function hashDirectory(string $path): string
    {
        $hashes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($path) + 1);
            if ($item->isDir()) {
                $hashes[] = 'dir:' . $relativePath;
                continue;
            }

            $hashes[] = 'file:' . $relativePath . ':' . $this->hashFile($item->getPathname());
        }

        sort($hashes);

        return $this->hashString(implode('|', $hashes));
    }

    /**
     * Returns a deterministic digest for a file path using the script-wide hash algorithm.
     */
    public function hashFile(string $path): string
    {
        $hash = hash_file(self::HASH_ALGO, $path);
        if ($hash === false) {
            throw new \RuntimeException(sprintf('Failed to hash file: %s', $path));
        }

        return $hash;
    }

    /**
     * Returns a deterministic digest for an in-memory string using the script-wide hash algorithm.
     */
    public function hashString(string $value): string
    {
        return hash(self::HASH_ALGO, $value);
    }
}
