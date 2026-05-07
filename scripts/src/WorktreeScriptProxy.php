<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script;

use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\GitClient;

/**
 * Detects whether the current script runs inside a git linked worktree
 * and transparently proxies execution to the equivalent script in the main worktree.
 */
final class WorktreeScriptProxy
{
    private const FORCE_FLAG = '--force-current-worktree';

    private bool $linkedWorktree;
    private string $currentRoot;
    private string $mainRoot;
    private string $relativePath;

    private function __construct(
        bool $linkedWorktree,
        string $currentRoot,
        string $mainRoot,
        string $relativePath
    ) {
        $this->linkedWorktree = $linkedWorktree;
        $this->currentRoot = $currentRoot;
        $this->mainRoot = $mainRoot;
        $this->relativePath = $relativePath;
    }

    /**
     * Detects the worktree context for the given script path.
     *
     * @param string $script Path to the current script (typically $argv[0])
     */
    public static function detect(string $script): self
    {
        $scriptPath = realpath($script);
        if ($scriptPath === false) {
            throw new \RuntimeException("Cannot resolve script path: {$script}");
        }

        $dir = dirname($scriptPath);
        $git = self::buildGitClient();

        $gitDir = $git->revParseInPath($dir, '--git-dir');
        $gitCommonDir = $git->revParseInPath($dir, '--git-common-dir');

        if ($gitDir === null || $gitCommonDir === null) {
            throw new \RuntimeException('Not inside a git repository.');
        }

        $resolvedGitDir = self::resolve($dir, $gitDir);
        $resolvedCommonDir = self::resolve($dir, $gitCommonDir);

        if ($resolvedGitDir === null || $resolvedCommonDir === null) {
            throw new \RuntimeException('Cannot resolve git directory paths.');
        }

        $isLinked = $resolvedGitDir !== $resolvedCommonDir;

        $currentRoot = $git->revParseInPath($dir, '--show-toplevel');
        if ($currentRoot === null) {
            throw new \RuntimeException('Cannot determine current worktree root.');
        }

        $mainRoot = dirname($resolvedCommonDir);
        $relativePath = ltrim(substr($scriptPath, strlen($currentRoot)), '/');

        return new self($isLinked, $currentRoot, $mainRoot, $relativePath);
    }

    /**
     * Proxies execution to the main worktree script when running inside a linked worktree.
     *
     * Returns immediately when already in the main worktree or when --force-current-worktree is set.
     * Always strips --force-current-worktree from $argv so downstream runners never see the proxy flag,
     * which would otherwise be consumed as the value of an option by argument parsers.
     *
     * @param array<string> $argv Mutated in place: --force-current-worktree is removed and indices reindexed.
     */
    public static function run(array &$argv): void
    {
        $hasForceFlag = in_array(self::FORCE_FLAG, $argv, true);
        if ($hasForceFlag) {
            $argv = array_values(array_filter($argv, static fn(string $arg): bool => $arg !== self::FORCE_FLAG));
        }

        try {
            $instance = self::detect($argv[0]);
        } catch (\RuntimeException) {
            return;
        }

        if (!$instance->isLinkedWorktree() || $hasForceFlag) {
            return;
        }

        $mainScript = $instance->getMainScriptPath();
        if (!is_file($mainScript)) {
            fwrite(STDERR, "❌ Script not found in main worktree: {$mainScript}\n");
            exit(1);
        }

        chdir($instance->getMainRoot());

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($mainScript);
        foreach (array_slice($argv, 1) as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        passthru($cmd, $exitCode);
        exit($exitCode);
    }

    /**
     * @return bool
     */
    public function isLinkedWorktree(): bool
    {
        return $this->linkedWorktree;
    }

    /**
     * @return string
     */
    public function getCurrentRoot(): string
    {
        return $this->currentRoot;
    }

    /**
     * @return string
     */
    public function getMainRoot(): string
    {
        return $this->mainRoot;
    }

    /**
     * @return string
     */
    public function getMainScriptPath(): string
    {
        return $this->mainRoot . '/' . $this->relativePath;
    }

    private static function buildGitClient(): GitClient
    {
        $app = Application::getInstance();
        $consoleClient = new ConsoleClient('', false, $app, fn(string $message) => null);

        return new GitClient(false, $consoleClient, new RetryPolicy(0, 0));
    }

    private static function resolve(string $base, string $path): ?string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        $resolved = realpath($base . '/' . $path);

        return $resolved !== false ? $resolved : null;
    }
}
