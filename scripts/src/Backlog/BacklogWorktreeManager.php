<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

use SoManAgent\Script\Client\AppScript;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\GitClient;
use SoManAgent\Script\Client\ProjectScriptClient;
use SoManAgent\Script\Backlog\ExternalWorktree;
use SoManAgent\Script\Backlog\ManagedWorktree;
use SoManAgent\Script\Backlog\WorktreeAction;
use SoManAgent\Script\Backlog\WorktreeClassification;
use SoManAgent\Script\Backlog\WorktreeState;

/**
 * Handles managed backlog worktrees and local git orchestration.
 */
final class BacklogWorktreeManager
{
    private string $projectRoot;
    private bool $dryRun;
    private string $backendEnvLocalFallback;
    private BacklogEntryResolver $entryResolver;
    private ConsoleClient $console;
    private GitClient $git;
    private ProjectScriptClient $scripts;

    /**
     * Creates the managed worktree service.
     */
    public function __construct(
        string $projectRoot,
        bool $dryRun,
        string $backendEnvLocalFallback,
        BacklogEntryResolver $entryResolver,
        ConsoleClient $console,
        GitClient $git,
        ProjectScriptClient $scripts,
    ) {
        $this->projectRoot = $projectRoot;
        $this->dryRun = $dryRun;
        $this->backendEnvLocalFallback = $backendEnvLocalFallback;
        $this->entryResolver = $entryResolver;
        $this->console = $console;
        $this->git = $git;
        $this->scripts = $scripts;
    }

    private function logVerbose(string $message): void
    {
        $this->console->logVerbose($message);
    }

    private function runCommand(string $command): void
    {
        $this->console->run($command);
    }

    private function capture(string $command): string
    {
        return $this->console->capture($command);
    }

    private function commandSucceeds(string $command): bool
    {
        return $this->console->succeeds($command);
    }

    /**
     * Ensures the managed worktree for an agent exists and is clean.
     */
    public function prepareAgentWorktree(string $agent): string
    {
        $path = $this->projectRoot . '/.worktrees/' . $agent;
        $relativePath = $this->toRelativeProjectPath($path);
        $exists = is_dir($path . '/.git') || is_file($path . '/.git');
        $created = false;

        if (!$exists) {
            $this->runGitCommand(sprintf('git worktree add --detach %s HEAD', escapeshellarg($relativePath)));
            $created = true;
            if ($this->dryRun) {
                $this->logVerbose('[dry-run] Skipping worktree status check for non-created path: ' . $relativePath);

                return $path;
            }
        }

        $this->ensureWorktreeRuntimeIgnores($path);
        $status = trim($this->captureGitOutput($this->gitInPath($path, 'status --short')));
        if ($status !== '') {
            throw new \RuntimeException("Agent worktree is dirty: {$path}");
        }

        $this->ensureWorktreeRuntimeState($path, $created);

        return $path;
    }

    /**
     * Ensures the worktree for an active backlog entry is ready on its branch.
     */
    public function prepareFeatureAgentWorktree(BoardEntry $entry): string
    {
        $agent = $entry->getAgent() ?? '';
        if ($agent === '') {
            throw new \RuntimeException('Feature has no assigned agent worktree.');
        }

        $branch = $entry->getBranch() ?? '';
        if ($branch === '') {
            throw new \RuntimeException('Feature has no branch metadata.');
        }

        $worktree = $this->prepareAgentWorktree($agent);
        $this->checkoutBranchInWorktree($worktree, $branch, false);

        return $worktree;
    }

    /**
     * @return array{path: string, temporary: bool}
     */
    public function prepareFeatureMergeWorktree(string $featureBranch, string $feature): array
    {
        $existingPath = $this->findWorktreePathForBranch($featureBranch);
        if ($existingPath !== null) {
            $dirty = trim($this->captureGitOutput($this->gitInPath($existingPath, 'status --short')));
            if ($dirty !== '') {
                throw new \RuntimeException(sprintf(
                    'Feature branch %s is still dirty in worktree %s. Clean it before feature-task-merge.',
                    $featureBranch,
                    $existingPath,
                ));
            }

            return ['path' => $existingPath, 'temporary' => false];
        }

        $path = $this->projectRoot . '/.worktrees/merge-' . $feature;
        $relativePath = $this->toRelativeProjectPath($path);
        if (is_dir($path) || is_file($path)) {
            throw new \RuntimeException(sprintf(
                'Temporary merge worktree path already exists: %s',
                $path,
            ));
        }

        $this->runGitCommand(sprintf(
            'git worktree add %s %s',
            escapeshellarg($relativePath),
            escapeshellarg($featureBranch),
        ));
        $this->ensureWorktreeRuntimeState($path, true);

        return ['path' => $path, 'temporary' => true];
    }

    /**
     * Removes a temporary merge worktree after verifying it is clean.
     */
    public function removeTemporaryMergeWorktree(string $path): void
    {
        if (!is_dir($path) && !is_file($path)) {
            return;
        }

        $dirty = trim($this->captureGitOutput($this->gitInPath($path, 'status --short')));
        if ($dirty !== '') {
            throw new \RuntimeException(sprintf(
                'Temporary merge worktree is dirty and cannot be removed automatically: %s',
                $path,
            ));
        }

        $this->runGitCommand(sprintf(
            'git worktree remove %s --force',
            escapeshellarg($this->toRelativeProjectPath($path)),
        ));
    }

    /**
     * Removes a merged task worktree when no active task still uses that agent.
     */
    public function cleanupMergedTaskWorktree(string $agent, string $taskBranch, BacklogBoard $board): void
    {
        if ($this->entryResolver->findTaskEntriesByAgent($board, $agent) !== []) {
            return;
        }

        $path = $this->projectRoot . '/.worktrees/' . $agent;
        if (!is_dir($path) && !is_file($path)) {
            return;
        }

        $boundBranch = $this->findBranchForWorktreePath($path);
        if ($boundBranch !== $taskBranch) {
            return;
        }

        $dirty = trim($this->captureGitOutput($this->gitInPath($path, 'status --short')));
        if ($dirty !== '') {
            throw new \RuntimeException(sprintf(
                'Task worktree for %s is dirty after merge and must be cleaned manually: %s',
                $agent,
                $path,
            ));
        }

        $this->runGitCommand(sprintf(
            'git worktree remove %s --force',
            escapeshellarg($this->toRelativeProjectPath($path)),
        ));
    }

    /**
     * Creates a local branch when it is missing.
     */
    public function ensureLocalBranchExists(string $branch, string $startPoint): void
    {
        if ($this->gitCommandSucceeds(sprintf(
            'git show-ref --verify --quiet %s',
            escapeshellarg('refs/heads/' . $branch),
        ))) {
            return;
        }

        $this->runGitCommand(sprintf(
            'git branch %s %s',
            escapeshellarg($branch),
            escapeshellarg($startPoint),
        ));
    }

    /**
     * Fails when a required local branch is missing.
     */
    public function requireLocalBranchExists(string $branch, string $context): void
    {
        if ($this->dryRun) {
            $this->logVerbose(sprintf(
                '[dry-run] Assuming local branch %s exists for %s.',
                $branch,
                $context,
            ));

            return;
        }

        if ($this->gitCommandSucceeds(sprintf(
            'git show-ref --verify --quiet %s',
            escapeshellarg('refs/heads/' . $branch),
        ))) {
            return;
        }

        throw new \RuntimeException(sprintf(
            '%s requires local branch %s to exist.',
            $context,
            $branch,
        ));
    }

    /**
     * Checks out an existing or newly created branch in a managed worktree.
     */
    public function checkoutBranchInWorktree(string $worktree, string $branch, bool $create, string $startPoint = 'origin/main'): void
    {
        if ($branch === '') {
            throw new \RuntimeException('Missing branch name.');
        }

        $this->releaseBranchFromOtherWorktrees($branch, $worktree);

        if ($this->dryRun && !is_dir($worktree . '/.git') && !is_file($worktree . '/.git')) {
            $this->logVerbose('[dry-run] Skipping worktree-local git inspection for non-created path: ' . $this->toRelativeProjectPath($worktree));
            if ($create) {
                $this->runGitCommand($this->gitInPath(
                    $worktree,
                    sprintf('checkout -B %s %s', escapeshellarg($branch), escapeshellarg($startPoint)),
                ));

                return;
            }

            $this->runGitCommand($this->gitInPath(
                $worktree,
                sprintf('checkout %s', escapeshellarg($branch)),
            ));

            return;
        }

        $currentBranch = null;
        if ($this->gitCommandSucceeds($this->gitInPath($worktree, 'symbolic-ref --quiet --short HEAD'))) {
            $currentBranch = trim($this->captureGitOutput($this->gitInPath($worktree, 'symbolic-ref --quiet --short HEAD')));
        }

        if (!$create && $currentBranch === $branch) {
            return;
        }

        if ($create) {
            $this->runGitCommand($this->gitInPath(
                $worktree,
                sprintf('checkout -B %s %s', escapeshellarg($branch), escapeshellarg($startPoint)),
            ));

            return;
        }

        $hasLocal = $this->gitCommandSucceeds($this->gitInPath(
            $worktree,
            sprintf('rev-parse --verify %s', escapeshellarg($branch)),
        ));
        if ($hasLocal) {
            $this->runGitCommand($this->gitInPath(
                $worktree,
                sprintf('checkout %s', escapeshellarg($branch)),
            ));

            return;
        }

        $this->runGitCommand($this->gitInPath(
            $worktree,
            sprintf('checkout -B %s origin/%s', escapeshellarg($branch), escapeshellarg($branch)),
        ));
    }

    /**
     * Fails when the branch is checked out in a dirty managed worktree.
     */
    public function ensureBranchHasNoDirtyManagedWorktree(string $branch): void
    {
        foreach ($this->listWorktreeBranchBindings() as $binding) {
            if ($binding['branch'] !== $branch) {
                continue;
            }

            $dirty = trim($this->captureGitOutput($this->gitInPath($binding['path'], 'status --short')));
            if ($dirty !== '') {
                throw new \RuntimeException(sprintf(
                    'Feature branch %s is still dirty in worktree %s. Commit or discard local changes before feature-close.',
                    $branch,
                    $binding['path'],
                ));
            }
        }
    }

    public function classifyWorktrees(BacklogBoard $board): WorktreeClassification
    {
        $managed = [];
        $external = [];
        $activeEntriesByBranch = $this->activeEntriesByBranch($board);
        $activeEntriesByAgent = $this->activeEntriesByAgent($board);

        foreach ($this->gitWorktreeBlocks() as $worktree) {
            $path = $worktree['path'];
            if ($path === $this->projectRoot) {
                continue;
            }

            if (!$this->isManagedAgentWorktree($path)) {
                $external[] = new ExternalWorktree(
                    $path,
                    $worktree['branch'],
                    $worktree['prunable'] ? WorktreeAction::MANUAL_PRUNE : WorktreeAction::MANUAL_REMOVE
                );
                continue;
            }

            $feature = null;
            $agent = null;
            $state = WorktreeState::ORPHAN;
            $action = WorktreeAction::CLEAN;
            $branch = $worktree['branch'];

            if ($worktree['prunable']) {
                $state = WorktreeState::PRUNABLE;
                $action = WorktreeAction::MANUAL_PRUNE;
            } elseif ($branch !== null && isset($activeEntriesByBranch[$branch])) {
                $feature = $activeEntriesByBranch[$branch]['feature'];
                $agent = $activeEntriesByBranch[$branch]['agent'];
                $expectedPath = $this->projectRoot . '/.worktrees/' . $agent;
                $dirty = $this->worktreeIsDirty($path);

                if ($path !== $expectedPath) {
                    $state = WorktreeState::BLOCKED;
                    $action = WorktreeAction::MANUAL_REVIEW;
                } elseif ($dirty) {
                    $state = WorktreeState::DIRTY;
                    $action = WorktreeAction::MANUAL_REVIEW;
                } else {
                    $state = WorktreeState::ACTIVE;
                    $action = WorktreeAction::KEEP;
                }
            } else {
                $agent = basename($path);
                if (isset($activeEntriesByAgent[$agent])) {
                    $feature = $activeEntriesByAgent[$agent]['feature'];
                    $state = WorktreeState::BLOCKED;
                    $action = WorktreeAction::MANUAL_REVIEW;
                } elseif ($this->worktreeIsDirty($path)) {
                    $state = WorktreeState::DIRTY;
                    $action = WorktreeAction::MANUAL_REVIEW;
                } elseif ($branch === null) {
                    $state = WorktreeState::DETACHED_MANAGED;
                    $action = WorktreeAction::CLEAN;
                }
            }

            $managed[] = new ManagedWorktree(
                $path,
                $branch,
                $feature,
                $agent,
                $state,
                $action
            );
        }

        return new WorktreeClassification($managed, $external);
    }

    /**
     * Removes managed worktrees that are no longer tied to active backlog entries.
     */
    public function cleanupAbandonedManagedWorktrees(BacklogBoard $board): int
    {
        $managed = $this->classifyWorktrees($board)->getManaged();

        $cleanable = array_values(array_filter(
            $managed,
            static fn(ManagedWorktree $item): bool => in_array($item->getState(), [WorktreeState::ORPHAN, WorktreeState::DETACHED_MANAGED], true),
        ));

        foreach ($cleanable as $item) {
            $this->runGitCommand(sprintf(
                'git worktree remove %s --force',
                escapeshellarg($this->toRelativeProjectPath($item->getPath())),
            ));
        }

        return count($cleanable);
    }

    /**
     * Removes abandoned managed worktrees still bound to the given branch.
     */
    public function cleanupManagedWorktreesForBranch(string $branch, BacklogBoard $board): int
    {
        if ($branch === '') {
            return 0;
        }

        $count = 0;
        foreach ($this->classifyWorktrees($board)->getManaged() as $item) {
            if ($item->getBranch() !== $branch) {
                continue;
            }
            if (!in_array($item->getState(), [WorktreeState::ORPHAN, WorktreeState::DETACHED_MANAGED], true)) {
                continue;
            }

            $this->runGitCommand(sprintf(
                'git worktree remove %s --force',
                escapeshellarg($this->toRelativeProjectPath($item->getPath())),
            ));
            $count++;
        }

        return $count;
    }

    /**
     * Runs the mechanical review script inside a worktree.
     */
    public function runReviewScript(string $worktree, ?string $base = null): void
    {
        $this->logVerbose(($this->dryRun ? '[dry-run] Would run review in ' : 'Run review in ') . $this->toRelativeProjectPath($worktree));
        if ($this->dryRun) {
            return;
        }

        $arguments = $base !== null && $base !== ''
            ? sprintf('--base=%s', escapeshellarg($base))
            : '';
        $this->scripts->run(AppScript::REVIEW, $arguments, projectRoot: $worktree);
    }

    private function ensureWorktreeRuntimeState(string $worktree, bool $created): void
    {
        $this->ensureWorktreeRuntimeIgnores($worktree);
        foreach ($this->copiedWorktreePaths() as $relativePath => $sourcePath) {
            if (!file_exists($sourcePath) && !is_link($sourcePath)) {
                throw new \RuntimeException("Missing dependency source in WP: {$sourcePath}");
            }

            $targetPath = $worktree . '/' . $relativePath;
            $parent = dirname($targetPath);
            if (!is_dir($parent)) {
                if ($this->dryRun) {
                    $this->logVerbose('[dry-run] Would create directory: ' . $this->toRelativeProjectPath($parent));
                    continue;
                }
                mkdir($parent, 0777, true);
            }

            if (!$created && (file_exists($targetPath) || is_link($targetPath))) {
                continue;
            }

            $this->replacePathWithCopy($sourcePath, $targetPath);
        }

        $this->syncWorktreeRootEnv($worktree);
        $this->writeBackendWorktreeEnvLocal($worktree);
    }

    private function ensureWorktreeRuntimeIgnores(string $worktree): void
    {
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Would update worktree git exclude for runtime dependency paths.');
            return;
        }

        $excludePath = trim($this->captureGitOutput($this->gitInPath($worktree, 'rev-parse --git-path info/exclude')));
        if ($excludePath === '') {
            return;
        }
        if (!str_starts_with($excludePath, '/')) {
            $excludePath = $worktree . '/' . $excludePath;
        }

        $parent = dirname($excludePath);
        if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
            throw new \RuntimeException("Unable to create git exclude directory: {$parent}");
        }

        $contents = is_file($excludePath) ? (string) file_get_contents($excludePath) : '';
        $lines = preg_split('/\R/', $contents) ?: [];

        foreach (array_keys($this->copiedWorktreePaths()) as $relativePath) {
            $pattern = '/' . trim($relativePath, '/') . '/';
            if (in_array($pattern, $lines, true)) {
                $this->hideTrackedRuntimePathChanges($worktree, $relativePath);

                continue;
            }
            $contents = rtrim($contents) . "\n" . $pattern . "\n";
            $lines[] = $pattern;
            $this->hideTrackedRuntimePathChanges($worktree, $relativePath);
        }

        if (file_put_contents($excludePath, ltrim($contents)) === false) {
            throw new \RuntimeException("Unable to update git exclude file: {$excludePath}");
        }
    }

    private function hideTrackedRuntimePathChanges(string $worktree, string $relativePath): void
    {
        $tracked = array_values(array_filter(explode("\n", trim($this->captureGitOutput($this->gitInPath(
            $worktree,
            sprintf('ls-files -- %s', escapeshellarg($relativePath)),
        ))))));
        if ($tracked === []) {
            return;
        }

        foreach (array_chunk($tracked, 50) as $chunk) {
            $this->runGitCommand($this->gitInPath(
                $worktree,
                'update-index --assume-unchanged -- ' . implode(' ', array_map('escapeshellarg', $chunk)),
            ));
        }
    }

    /**
     * @return array<string, string>
     */
    private function copiedWorktreePaths(): array
    {
        return [
            'scripts/vendor' => $this->projectRoot . '/scripts/vendor',
            'backend/vendor' => $this->projectRoot . '/backend/vendor',
            'frontend/node_modules' => $this->projectRoot . '/frontend/node_modules',
        ];
    }

    private function replacePathWithCopy(string $sourcePath, string $targetPath): void
    {
        $this->removeFilesystemPath($targetPath);
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Would copy path: ' . $this->toRelativeProjectPath($sourcePath) . ' -> ' . $this->toRelativeProjectPath($targetPath));
            return;
        }
        $this->copyFilesystemPath($sourcePath, $targetPath);
    }

    private function syncWorktreeRootEnv(string $worktree): void
    {
        $sourcePath = $this->projectRoot . '/.env';
        if (!is_file($sourcePath)) {
            throw new \RuntimeException('Missing root .env in WP.');
        }

        $this->replacePathWithCopy($sourcePath, $worktree . '/.env');
    }

    private function writeBackendWorktreeEnvLocal(string $worktree): void
    {
        $targetPath = $worktree . '/backend/.env.local';
        $contents = $this->buildBackendWorktreeEnvLocalContents();
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Would write file: ' . $this->toRelativeProjectPath($targetPath));
            return;
        }

        if (file_put_contents($targetPath, $contents) === false) {
            throw new \RuntimeException("Unable to write file: {$targetPath}");
        }
    }

    private function buildBackendWorktreeEnvLocalContents(): string
    {
        $envFile = $this->projectRoot . '/.env';
        $content = @file_get_contents($envFile);
        if ($content === false) {
            return $this->backendEnvLocalFallback;
        }

        if (preg_match('/^DATABASE_URL=(["\']?)(.+)\1$/m', $content, $matches) !== 1) {
            return $this->backendEnvLocalFallback;
        }

        $databaseUrl = trim($matches[2]);
        $localUrl = preg_replace('/@db(?=[:\/])/', '@localhost', $databaseUrl, 1);
        if (!is_string($localUrl) || $localUrl === $databaseUrl) {
            return $this->backendEnvLocalFallback;
        }

        return sprintf("DATABASE_URL=\"%s\"\n", $localUrl);
    }

    private function removeFilesystemPath(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Would remove path: ' . $this->toRelativeProjectPath($path));
            return;
        }

        if (is_file($path) || is_link($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException("Unable to remove path: {$path}");
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
                    throw new \RuntimeException("Unable to remove directory: {$item->getPathname()}");
                }
                continue;
            }

            if (!unlink($item->getPathname())) {
                throw new \RuntimeException("Unable to remove path: {$item->getPathname()}");
            }
        }

        if (!rmdir($path)) {
            throw new \RuntimeException("Unable to remove directory: {$path}");
        }
    }

    private function copyFilesystemPath(string $sourcePath, string $targetPath): void
    {
        if (is_link($sourcePath)) {
            $linkTarget = readlink($sourcePath);
            if ($linkTarget === false || !symlink($linkTarget, $targetPath)) {
                throw new \RuntimeException("Unable to copy symlink: {$sourcePath}");
            }

            return;
        }

        if (is_file($sourcePath)) {
            if (!copy($sourcePath, $targetPath)) {
                throw new \RuntimeException("Unable to copy file: {$sourcePath}");
            }

            return;
        }

        if (!is_dir($targetPath) && !mkdir($targetPath, 0777, true) && !is_dir($targetPath)) {
            throw new \RuntimeException("Unable to create directory: {$targetPath}");
        }

        $iterator = new \FilesystemIterator($sourcePath, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            $this->copyFilesystemPath($item->getPathname(), $targetPath . '/' . $item->getBasename());
        }
    }

    private function releaseBranchFromOtherWorktrees(string $branch, string $keepWorktree): void
    {
        $output = $this->captureGitOutput('git worktree list --porcelain');
        $blocks = preg_split('/\n\n/', trim($output)) ?: [];

        foreach ($blocks as $block) {
            $path = null;
            $ref = null;

            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'worktree ')) {
                    $path = substr($line, 9);
                }
                if (str_starts_with($line, 'branch refs/heads/')) {
                    $ref = substr($line, strlen('branch refs/heads/'));
                }
            }

            if ($path === null || $ref !== $branch || realpath($path) === realpath($keepWorktree)) {
                continue;
            }

            $dirty = trim($this->captureGitOutput($this->gitInPath($path, 'status --short')));
            if ($dirty !== '') {
                throw new \RuntimeException("Branch {$branch} is still active in a dirty worktree: {$path}");
            }

            if (!str_starts_with($path, $this->projectRoot . '/.worktrees/')) {
                throw new \RuntimeException("Branch {$branch} is active in a non-managed worktree: {$path}");
            }

            $this->runGitCommand(sprintf('git worktree remove %s --force', escapeshellarg($this->toRelativeProjectPath($path))));
        }
    }

    /**
     * @return array<int, array{path: string, branch: string|null}>
     */
    private function listWorktreeBranchBindings(): array
    {
        $blocks = $this->gitWorktreeBlocks();
        $bindings = [];

        foreach ($blocks as $block) {
            $path = $block['path'];
            $branch = $block['branch'];

            if ($path !== null) {
                $bindings[] = ['path' => $path, 'branch' => $branch];
            }
        }

        return $bindings;
    }

    private function findWorktreePathForBranch(string $branch): ?string
    {
        foreach ($this->listWorktreeBranchBindings() as $binding) {
            if (($binding['branch'] ?? null) !== $branch) {
                continue;
            }

            return $binding['path'];
        }

        return null;
    }

    private function findBranchForWorktreePath(string $path): ?string
    {
        $realPath = realpath($path);
        foreach ($this->listWorktreeBranchBindings() as $binding) {
            $bindingPath = realpath($binding['path']);
            if ($bindingPath === false || $realPath === false || $bindingPath !== $realPath) {
                continue;
            }

            return $binding['branch'];
        }

        return null;
    }

    /**
     * @return array<string, array{feature: string, agent: string}>
     */
    private function activeEntriesByBranch(BacklogBoard $board): array
    {
        $features = [];
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            $branch = $entry->getBranch() ?? '';
            $feature = $entry->getFeature() ?? '';
            $agent = $entry->getAgent() ?? '';
            if ($branch === '' || $feature === '' || $agent === '') {
                continue;
            }

            $features[$branch] = [
                'feature' => $feature,
                'agent' => $agent,
            ];
        }

        return $features;
    }

    /**
     * @return array<string, array{feature: string, branch: string|null}>
     */
    private function activeEntriesByAgent(BacklogBoard $board): array
    {
        $entries = [];
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            $agent = $entry->getAgent() ?? '';
            $feature = $entry->getFeature() ?? '';
            if ($agent === '' || $feature === '') {
                continue;
            }

            $entries[$agent] = [
                'feature' => $feature,
                'branch' => $entry->getBranch(),
            ];
        }

        return $entries;
    }

    /**
     * @return array<int, array{path: string, branch: string|null, prunable: bool}>
     */
    private function gitWorktreeBlocks(): array
    {
        $output = trim($this->captureGitOutput('git worktree list --porcelain'));
        if ($output === '') {
            return [];
        }

        $blocks = preg_split('/\n\n/', $output) ?: [];
        $worktrees = [];

        foreach ($blocks as $block) {
            $path = null;
            $branch = null;
            $prunable = false;

            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'worktree ')) {
                    $path = substr($line, 9);
                    continue;
                }
                if (str_starts_with($line, 'branch refs/heads/')) {
                    $branch = substr($line, strlen('branch refs/heads/'));
                    continue;
                }
                if (str_starts_with($line, 'prunable ')) {
                    $prunable = true;
                }
            }

            if ($path !== null) {
                $worktrees[] = [
                    'path' => $path,
                    'branch' => $branch,
                    'prunable' => $prunable,
                ];
            }
        }

        return $worktrees;
    }

    private function isManagedAgentWorktree(string $path): bool
    {
        return str_starts_with($path, $this->projectRoot . '/.worktrees/');
    }

    private function worktreeIsDirty(string $path): bool
    {
        if (!is_dir($path) && !is_file($path)) {
            return false;
        }

        return trim($this->captureGitOutput($this->gitInPath($path, 'status --short'))) !== '';
    }

    private function runGitCommand(string $command): void
    {
        $this->git->run($command);
    }

    private function captureGitOutput(string $command): string
    {
        return $this->git->capture($command);
    }

    private function gitCommandSucceeds(string $command): bool
    {
        return $this->git->succeeds($command);
    }

    private function gitInPath(string $path, string $subCommand): string
    {
        return $this->git->inPath($path, $subCommand);
    }

    private function toRelativeProjectPath(string $path): string
    {
        return $this->console->toRelativeProjectPath($path);
    }
}
