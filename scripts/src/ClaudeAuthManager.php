<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

/**
 * Manages Claude CLI authentication with WSL as the source of truth and Docker as a synchronized runtime copy.
 */
final class ClaudeAuthManager
{
    private const HASH_ALGO = 'sha256';
    private const CLAUDE_CONTAINERS = 'php worker';

    private readonly Console $console;
    private readonly string $wslClaudeDir;
    private readonly string $wslClaudeJson;
    private readonly string $sharedRoot;
    private readonly string $sharedClaudeDir;
    private readonly string $sharedClaudeJson;

    public function __construct(
        private readonly Application $app,
        string $root,
    ) {
        $this->console = $app->console;
        $root = rtrim($root, '/');

        $wslHome = rtrim((string) getenv('HOME'), '/');
        $this->wslClaudeDir = $wslHome . '/.claude';
        $this->wslClaudeJson = $wslHome . '/.claude.json';
        $this->sharedRoot = $root . '/.docker/claude/shared';
        $this->sharedClaudeDir = $this->sharedRoot . '/.claude';
        $this->sharedClaudeJson = $this->sharedRoot . '/.claude.json';
    }

    /**
     * Prints WSL auth state, synchronized Docker copy state, and Claude auth visibility inside Docker.
     */
    public function showStatus(): void
    {
        $this->console->step('Checking WSL Claude auth files');
        $this->console->info($this->describePathState($this->wslClaudeDir));
        $this->console->info($this->describePathState($this->wslClaudeJson));

        $this->console->step('Checking Docker shared auth files');
        $this->console->info($this->describePathState($this->sharedClaudeDir));
        $this->console->info($this->describePathState($this->sharedClaudeJson));

        if (is_dir($this->wslClaudeDir) && is_dir($this->sharedClaudeDir)) {
            $sameDir = $this->hashDirectory($this->wslClaudeDir) === $this->hashDirectory($this->sharedClaudeDir);
            $this->console->info($sameDir ? 'Shared .claude directory is in sync with WSL.' : 'Shared .claude directory differs from WSL.');
        }

        if (is_file($this->wslClaudeJson) && is_file($this->sharedClaudeJson)) {
            $sameJson = $this->hashFile($this->wslClaudeJson) === $this->hashFile($this->sharedClaudeJson);
            $this->console->info($sameJson ? 'Shared .claude.json file is in sync with WSL.' : 'Shared .claude.json file differs from WSL.');
        }

        $this->console->step('Checking Claude auth status in WSL');
        $this->printCommandStatus('claude auth status');

        $this->console->step('Checking Claude auth status inside Docker');
        $this->printCommandStatus('docker compose run --rm --no-deps php sh -lc \'HOME=/claude-home claude auth status\'');
    }

    /**
     * Synchronizes the WSL Claude auth files into the Docker shared mount, then recreates the consuming containers.
     */
    public function sync(bool $force = false): void
    {
        $this->assertWslAuthExists();

        if (!$force) {
            $this->confirmOverwrite(
                'This will overwrite the Docker Claude auth copy from your WSL auth state.',
            );
        }

        $this->console->step('Syncing WSL Claude auth to Docker shared directory');
        $this->clearDockerSharedCopy();
        $this->ensureDirectory($this->sharedRoot);
        $this->ensureDirectory($this->sharedClaudeDir);
        $this->clearDirectoryContents($this->sharedClaudeDir);
        $this->copyDirectory($this->wslClaudeDir, $this->sharedClaudeDir);
        $this->copyFile($this->wslClaudeJson, $this->sharedClaudeJson);
        $this->recreateClaudeContainers();
        $this->console->ok('Docker Claude auth copy updated from WSL.');
    }

    /**
     * Performs Claude login in WSL, then synchronizes the resulting auth state into Docker.
     */
    public function loginAndSync(bool $force = false): void
    {
        $this->console->step('Running Claude login in WSL');
        $this->runRequiredCommand('claude auth login', 'Claude login failed.');

        $this->sync($force ?: true);
    }

    /**
     * Ensures the WSL Claude auth files exist before attempting a sync.
     */
    private function assertWslAuthExists(): void
    {
        if (!is_dir($this->wslClaudeDir)) {
            throw new \RuntimeException(sprintf('WSL Claude auth directory not found: %s', $this->wslClaudeDir));
        }

        if (!is_file($this->wslClaudeJson)) {
            throw new \RuntimeException(sprintf('WSL Claude auth file not found: %s', $this->wslClaudeJson));
        }
    }

    /**
     * Asks for an explicit confirmation before overwriting the Docker Claude auth copy.
     */
    private function confirmOverwrite(string $message): void
    {
        $this->console->warn($message);
        $this->console->warn('Type "yes" to continue:');

        $confirmation = trim((string) fgets(STDIN));
        if ($confirmation !== 'yes') {
            throw new \RuntimeException('Aborted.');
        }
    }

    /**
     * Clears the shared Docker auth copy through an ephemeral container so host-side cleanup is not blocked by container-owned files.
     */
    private function clearDockerSharedCopy(): void
    {
        $this->console->info('Resetting Docker shared Claude auth copy before sync.');
        $this->runRequiredCommand(
            $this->buildEphemeralPhpShellCommand(
                'if [ -d /claude-home/.claude ]; then find /claude-home/.claude -mindepth 1 -delete; fi',
            ),
            'Failed to clear Docker Claude auth copy.',
        );
    }

    /**
     * Recreates the PHP and worker containers so they read the refreshed auth files from the shared mount.
     */
    private function recreateClaudeContainers(): void
    {
        $this->console->info('Recreating PHP and worker containers to refresh Claude auth mounts.');
        $this->removeClaudeContainers();
        $this->runRequiredCommand(
            sprintf('docker compose up -d %s', self::CLAUDE_CONTAINERS),
            'Failed to recreate Claude-enabled containers.',
        );
    }

    /**
     * Removes the Claude-enabled runtime containers when they already exist.
     */
    private function removeClaudeContainers(): void
    {
        $this->app->runCommand(sprintf('docker compose rm -sf %s >/dev/null 2>&1 || true', self::CLAUDE_CONTAINERS));
    }

    /**
     * Builds a one-shot PHP container command for maintenance tasks on the shared Claude auth mount.
     */
    private function buildEphemeralPhpShellCommand(string $shellCommand): string
    {
        return sprintf(
            'docker compose run --rm --no-deps php sh -lc %s',
            escapeshellarg($shellCommand),
        );
    }

    /**
     * Runs a shell command and fails with a contextual message when it exits unsuccessfully.
     */
    private function runRequiredCommand(string $command, string $errorPrefix): void
    {
        $exitCode = $this->app->runCommand($command);
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf('%s (exit %d).', $errorPrefix, $exitCode));
        }
    }

    /**
     * Runs a shell command, prints its output, and reports whether it succeeded without aborting status reporting.
     */
    private function printCommandStatus(string $command): void
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
    private function describePathState(string $path): string
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
     * Creates a directory recursively when it does not already exist.
     */
    private function ensureDirectory(string $path): void
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
    private function removePath(string $path): void
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
    private function clearDirectoryContents(string $path): void
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
    private function copyDirectory(string $source, string $destination): void
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
    private function copyFile(string $source, string $destination): void
    {
        $this->ensureDirectory(dirname($destination));

        if (!copy($source, $destination)) {
            throw new \RuntimeException(sprintf('Failed to copy file from %s to %s', $source, $destination));
        }

        @chmod($destination, 0600);
    }

    /**
     * Builds a stable hash of a directory tree contents and relative paths.
     */
    private function hashDirectory(string $path): string
    {
        $hashes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($path) + 1);
            if ($item->isDir()) {
                $hashes[] = 'dir:' . $relativePath;
                continue;
            }

            $fileHash = $this->hashFile($item->getPathname());
            $hashes[] = 'file:' . $relativePath . ':' . $fileHash;
        }

        sort($hashes);

        return $this->hashString(implode('|', $hashes));
    }

    /**
     * Returns a deterministic digest for a file path using the script-wide hash algorithm.
     */
    private function hashFile(string $path): string
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
    private function hashString(string $value): string
    {
        return hash(self::HASH_ALGO, $value);
    }
}
