<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

/**
 * Handles managed backlog worktrees and local git orchestration.
 */
final class BacklogWorktreeManager
{
    private string $projectRoot;
    private bool $dryRun;
    private string $backendEnvLocalFallback;
    private BacklogEntryResolver $entryResolver;
    private BacklogShell $shell;

    public function __construct(
        string $projectRoot,
        bool $dryRun,
        string $backendEnvLocalFallback,
        BacklogEntryResolver $entryResolver,
        BacklogShell $shell,
    ) {
        $this->projectRoot = $projectRoot;
        $this->dryRun = $dryRun;
        $this->backendEnvLocalFallback = $backendEnvLocalFallback;
        $this->entryResolver = $entryResolver;
        $this->shell = $shell;
    }

    private function logVerbose(string $message): void
    {
        $this->shell->logVerbose($message);
    }

    private function runCommand(string $command): void
    {
        $this->shell->run($command);
    }

    private function capture(string $command): string
    {
        return $this->shell->capture($command);
    }

    private function commandSucceeds(string $command): bool
    {
        return $this->shell->succeeds($command);
    }

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

        $status = trim($this->captureGitOutput($this->gitInPath($path, 'status --short')));
        if ($status !== '') {
            throw new \RuntimeException("Agent worktree is dirty: {$path}");
        }

        $this->ensureWorktreeRuntimeState($path, $created);

        return $path;
    }

    public function prepareFeatureAgentWorktree(BoardEntry $entry): string
    {
        $agent = $entry->getMeta('agent') ?? '';
        if ($agent === '') {
            throw new \RuntimeException('Feature has no assigned agent worktree.');
        }

        $branch = $entry->getMeta('branch') ?? '';
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

    /**
     * @return array{managed: array<int, array{path: string, branch: string|null, feature: string|null, agent: string|null, state: string, action: string}>, external: array<int, array{path: string, branch: string|null, action: string}>}
     */
    public function classifyWorktrees(BacklogBoard $board): array
    {
        $managed = [];
        $external = [];
        $activeFeatures = $this->activeFeaturesByBranch($board);

        foreach ($this->gitWorktreeBlocks() as $worktree) {
            $path = $worktree['path'];
            if ($path === $this->projectRoot) {
                continue;
            }

            if (!$this->isManagedAgentWorktree($path)) {
                $external[] = [
                    'path' => $path,
                    'branch' => $worktree['branch'],
                    'action' => $worktree['prunable'] ? 'manual-prune' : 'manual-remove',
                ];
                continue;
            }

            $feature = null;
            $agent = null;
            $state = 'orphan';
            $action = 'clean';
            $branch = $worktree['branch'];

            if ($worktree['prunable']) {
                $state = 'prunable';
                $action = 'manual-prune';
            } elseif ($branch !== null && isset($activeFeatures[$branch])) {
                $feature = $activeFeatures[$branch]['feature'];
                $agent = $activeFeatures[$branch]['agent'];
                $expectedPath = $this->projectRoot . '/.worktrees/' . $agent;
                $dirty = $this->worktreeIsDirty($path);

                if ($path !== $expectedPath) {
                    $state = 'blocked';
                    $action = 'manual-review';
                } elseif ($dirty) {
                    $state = 'dirty';
                    $action = 'manual-review';
                } else {
                    $state = 'active';
                    $action = 'keep';
                }
            } else {
                $agent = basename($path);
                if ($this->worktreeIsDirty($path)) {
                    $state = 'dirty';
                    $action = 'manual-review';
                } elseif ($branch === null) {
                    $state = 'detached-managed';
                    $action = 'clean';
                }
            }

            $managed[] = [
                'path' => $path,
                'branch' => $branch,
                'feature' => $feature,
                'agent' => $agent,
                'state' => $state,
                'action' => $action,
            ];
        }

        return ['managed' => $managed, 'external' => $external];
    }

    public function cleanupAbandonedManagedWorktrees(BacklogBoard $board): int
    {
        ['managed' => $managed] = $this->classifyWorktrees($board);

        $cleanable = array_values(array_filter(
            $managed,
            static fn(array $item): bool => in_array($item['state'], ['orphan', 'detached-managed'], true),
        ));

        foreach ($cleanable as $item) {
            $this->runGitCommand(sprintf(
                'git worktree remove %s --force',
                escapeshellarg($this->toRelativeProjectPath($item['path'])),
            ));
        }

        return count($cleanable);
    }

    public function cleanupManagedWorktreesForBranch(string $branch, BacklogBoard $board): int
    {
        if ($branch === '') {
            return 0;
        }

        $count = 0;
        foreach ($this->classifyWorktrees($board)['managed'] as $item) {
            if (($item['branch'] ?? null) !== $branch) {
                continue;
            }
            if (!in_array($item['state'], ['orphan', 'detached-managed'], true)) {
                continue;
            }

            $this->runGitCommand(sprintf(
                'git worktree remove %s --force',
                escapeshellarg($this->toRelativeProjectPath($item['path'])),
            ));
            $count++;
        }

        return $count;
    }

    public function runReviewScript(string $worktree): void
    {
        $this->logVerbose(($this->dryRun ? '[dry-run] Would run review in ' : 'Run review in ') . $this->toRelativeProjectPath($worktree));
        if ($this->dryRun) {
            return;
        }

        $this->runCommand(sprintf(
            'cd %s && php scripts/review.php',
            escapeshellarg($this->toRelativeProjectPath($worktree)),
        ));
    }

    private function ensureWorktreeRuntimeState(string $worktree, bool $created): void
    {
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

    /**
     * @return array<string, string>
     */
    private function copiedWorktreePaths(): array
    {
        return [
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
    private function activeFeaturesByBranch(BacklogBoard $board): array
    {
        $features = [];
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            $branch = $entry->getMeta('branch') ?? '';
            $feature = $entry->getMeta('feature') ?? '';
            $agent = $entry->getMeta('agent') ?? '';
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
        $this->shell->runGit($command);
    }

    private function captureGitOutput(string $command): string
    {
        return $this->shell->captureGit($command);
    }

    private function gitCommandSucceeds(string $command): bool
    {
        return $this->shell->gitSucceeds($command);
    }

    private function gitInPath(string $path, string $subCommand): string
    {
        return $this->shell->gitInPath($path, $subCommand);
    }

    private function toRelativeProjectPath(string $path): string
    {
        return $this->shell->toRelativeProjectPath($path);
    }
}
