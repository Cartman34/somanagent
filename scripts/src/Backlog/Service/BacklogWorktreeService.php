<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Service;

use SoManAgent\Script\Backlog\Enum\WorktreeAction;
use SoManAgent\Script\Backlog\Enum\WorktreeState;
use SoManAgent\Script\Backlog\Model\ActiveEntryReference;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Model\ExternalWorktree;
use SoManAgent\Script\Backlog\Model\ManagedWorktree;
use SoManAgent\Script\Backlog\Model\WorktreeClassification;
use SoManAgent\Script\Client\AppScript;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\FilesystemClientInterface;
use SoManAgent\Script\Client\GitClient;
use SoManAgent\Script\Client\ProjectScriptClient;

/**
 * Handles managed backlog worktrees and local git orchestration.
 */
final class BacklogWorktreeService
{
    private string $projectRoot;
    private bool $dryRun;
    private string $backendEnvLocalFallback;
    private BacklogBoardService $boardService;
    private ConsoleClient $console;
    private GitClient $git;
    private ProjectScriptClient $scripts;
    private FilesystemClientInterface $fs;

    public function __construct(
        string $projectRoot,
        bool $dryRun,
        string $backendEnvLocalFallback,
        BacklogBoardService $boardService,
        ConsoleClient $console,
        GitClient $git,
        ProjectScriptClient $scripts,
        FilesystemClientInterface $fs
    ) {
        $this->projectRoot = $projectRoot;
        $this->dryRun = $dryRun;
        $this->backendEnvLocalFallback = $backendEnvLocalFallback;
        $this->boardService = $boardService;
        $this->console = $console;
        $this->git = $git;
        $this->scripts = $scripts;
        $this->fs = $fs;
    }

    /**
     * Ensures the managed worktree for an agent exists and is clean.
     */
    public function prepareAgentWorktree(string $agent): string
    {
        $path = $this->projectRoot . '/.worktrees/' . $agent;
        $relativePath = $this->git->toRelativeProjectPath($path);
        $exists = $this->fs->checkPathExists($path . '/.git');
        $created = false;

        if (!$exists) {
            $this->git->addWorktreeDetach($path);
            $created = true;
            if ($this->dryRun) {
                $this->logVerbose('[dry-run] Skipping worktree status check for non-created path: ' . $relativePath);

                return $path;
            }
        }

        $this->ensureWorktreeRuntimeIgnores($path);
        if ($this->git->hasLocalChanges($path)) {
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
            if ($this->git->hasLocalChanges($existingPath)) {
                throw new \RuntimeException(sprintf(
                    'Feature branch %s is still dirty in worktree %s. Clean it before feature-task-merge.',
                    $featureBranch,
                    $existingPath,
                ));
            }

            return ['path' => $existingPath, 'temporary' => false];
        }

        $path = $this->projectRoot . '/.worktrees/merge-' . $feature;
        if ($this->fs->checkPathExists($path)) {
            throw new \RuntimeException(sprintf(
                'Temporary merge worktree path already exists: %s',
                $path,
            ));
        }

        $this->git->addWorktree($path, $featureBranch);
        $this->ensureWorktreeRuntimeState($path, true);

        return ['path' => $path, 'temporary' => true];
    }

    public function removeTemporaryMergeWorktree(string $path): void
    {
        if (!$this->fs->checkPathExists($path)) {
            return;
        }

        if ($this->git->hasLocalChanges($path)) {
            throw new \RuntimeException(sprintf(
                'Temporary merge worktree is dirty and cannot be removed automatically: %s',
                $path,
            ));
        }

        $this->git->removeWorktreeForce($path);
    }

    public function cleanupMergedTaskWorktree(string $agent, string $taskBranch, BacklogBoard $board): void
    {
        if ($this->boardService->findTaskEntriesByAgent($board, $agent) !== []) {
            return;
        }

        $path = $this->projectRoot . '/.worktrees/' . $agent;
        if (!$this->fs->checkPathExists($path)) {
            return;
        }

        $boundBranch = $this->findBranchForWorktreePath($path);
        if ($boundBranch !== $taskBranch) {
            return;
        }

        if ($this->git->hasLocalChanges($path)) {
            throw new \RuntimeException(sprintf(
                'Task worktree for %s is dirty after merge and must be cleaned manually: %s',
                $agent,
                $path,
            ));
        }

        $this->git->removeWorktreeForce($path);
    }

    public function ensureLocalBranchExists(string $branch, string $startPoint): void
    {
        if ($this->git->localBranchExists($branch)) {
            return;
        }

        $this->git->createBranch($branch, $startPoint);
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

        if ($this->git->localBranchExists($branch)) {
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

        if ($this->dryRun && !$this->fs->checkPathExists($worktree . '/.git')) {
            $this->logVerbose('[dry-run] Skipping worktree-local git inspection for non-created path: ' . $this->git->toRelativeProjectPath($worktree));
            if ($create) {
                $this->git->checkoutBranchCreate($worktree, $branch, $startPoint);

                return;
            }

            $this->git->checkoutBranch($worktree, $branch);

            return;
        }

        $currentBranch = $this->git->currentBranch($worktree);

        if (!$create && $currentBranch === $branch) {
            return;
        }

        if ($create) {
            $this->git->checkoutBranchCreate($worktree, $branch, $startPoint);

            return;
        }

        // Check if branch exists locally or on remote
        if ($this->git->localBranchExists($branch)) {
            $this->git->checkoutBranch($worktree, $branch);

            return;
        }

        $this->git->checkoutBranchCreate($worktree, $branch, 'origin/' . $branch);
    }

    public function assertBranchHasNoDirtyManagedWorktree(string $branch): void
    {
        foreach ($this->listWorktreeBranchBindings() as $binding) {
            if ($binding['branch'] !== $branch) {
                continue;
            }

            if ($this->git->hasLocalChanges($binding['path'])) {
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
        $activeEntriesByBranch = $this->fetchActiveEntriesByBranch($board);
        $activeEntriesByAgent = $this->fetchActiveEntriesByAgent($board);

        foreach ($this->fetchGitWorktreeBlocks() as $worktree) {
            $path = $worktree['path'];
            if ($path === $this->projectRoot) {
                continue;
            }

            if (!$this->checkIsManagedAgentWorktree($path)) {
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
                $feature = $activeEntriesByBranch[$branch]->getFeature();
                $agent = $activeEntriesByBranch[$branch]->getAgent();
                $expectedPath = $this->projectRoot . '/.worktrees/' . $agent;
                $dirty = $this->git->hasLocalChanges($path);

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
                // Agent is the last folder of the path
                $agent = preg_replace('/^.*\/([^\/]+)$/', '$1', $path);
                if (isset($activeEntriesByAgent[$agent])) {
                    $feature = $activeEntriesByAgent[$agent]->getFeature();
                    $state = WorktreeState::BLOCKED;
                    $action = WorktreeAction::MANUAL_REVIEW;
                } elseif ($this->git->hasLocalChanges($path)) {
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

    public function cleanupAbandonedManagedWorktrees(BacklogBoard $board): int
    {
        $managed = $this->classifyWorktrees($board)->getManaged();

        $cleanable = array_values(array_filter(
            $managed,
            static fn(ManagedWorktree $item): bool => in_array($item->getState(), [WorktreeState::ORPHAN, WorktreeState::DETACHED_MANAGED], true),
        ));

        foreach ($cleanable as $item) {
            $this->git->removeWorktreeForce($item->getPath());
        }

        return count($cleanable);
    }

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

            $this->git->removeWorktreeForce($item->getPath());
            $count++;
        }

        return $count;
    }

    public function runReviewScript(string $worktree, ?string $base = null): void
    {
        $this->logVerbose(($this->dryRun ? '[dry-run] Would run review in ' : 'Run review in ') . $this->git->toRelativeProjectPath($worktree));
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
        foreach ($this->fetchCopiedWorktreePaths() as $relativePath => $sourcePath) {
            if (!$this->fs->checkPathExists($sourcePath)) {
                throw new \RuntimeException("Missing dependency source in WP: {$sourcePath}");
            }

            $targetPath = $worktree . '/' . $relativePath;
            $parent = preg_replace('/\/[^\/]+$/', '', $targetPath);
            if (!$this->fs->isDirectory($parent)) {
                if ($this->dryRun) {
                    $this->logVerbose('[dry-run] Would create directory: ' . $this->git->toRelativeProjectPath($parent));
                    continue;
                }
                $this->fs->makeDirectory($parent);
            }

            if (!$created && $this->fs->checkPathExists($targetPath)) {
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

        $excludePath = $this->git->getGitPath($worktree, 'info/exclude');
        if ($excludePath === '') {
            return;
        }
        if (!str_starts_with($excludePath, '/')) {
            $excludePath = $worktree . '/' . $excludePath;
        }

        $parent = preg_replace('/\/[^\/]+$/', '', $excludePath);
        if (!$this->fs->isDirectory($parent)) {
            $this->fs->makeDirectory($parent);
        }

        $contents = $this->fs->isFile($excludePath) ? $this->fs->getFileContents($excludePath) : '';
        $lines = preg_split('/\R/', $contents) ?: [];

        foreach (array_keys($this->fetchCopiedWorktreePaths()) as $relativePath) {
            $pattern = '/' . trim($relativePath, '/') . '/';
            if (in_array($pattern, $lines, true)) {
                $this->hideTrackedRuntimePathChanges($worktree, $relativePath);

                continue;
            }
            $contents = rtrim($contents) . "\n" . $pattern . "\n";
            $lines[] = $pattern;
            $this->hideTrackedRuntimePathChanges($worktree, $relativePath);
        }

        $this->fs->writeFilePath($excludePath, ltrim($contents));
    }

    private function hideTrackedRuntimePathChanges(string $worktree, string $relativePath): void
    {
        $tracked = $this->git->getTrackedFiles($worktree, $relativePath);
        if ($tracked === []) {
            return;
        }

        foreach (array_chunk($tracked, 50) as $chunk) {
            $this->git->updateIndexAssumeUnchanged($worktree, $chunk);
        }
    }

    /**
     * @return array<string, string>
     */
    private function fetchCopiedWorktreePaths(): array
    {
        return [
            'scripts/vendor' => $this->projectRoot . '/scripts/vendor',
            'backend/vendor' => $this->projectRoot . '/backend/vendor',
            'frontend/node_modules' => $this->projectRoot . '/frontend/node_modules',
        ];
    }

    private function replacePathWithCopy(string $sourcePath, string $targetPath): void
    {
        if ($this->fs->checkPathExists($targetPath)) {
            $this->fs->removePath($targetPath);
        }

        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Would copy path: ' . $this->git->toRelativeProjectPath($sourcePath) . ' -> ' . $this->git->toRelativeProjectPath($targetPath));
            return;
        }
        $this->fs->copyPath($sourcePath, $targetPath);
    }

    private function syncWorktreeRootEnv(string $worktree): void
    {
        $sourcePath = $this->projectRoot . '/.env';
        if (!$this->fs->isFile($sourcePath)) {
            throw new \RuntimeException('Missing root .env in WP.');
        }

        $this->replacePathWithCopy($sourcePath, $worktree . '/.env');
    }

    private function writeBackendWorktreeEnvLocal(string $worktree): void
    {
        $targetPath = $worktree . '/backend/.env.local';
        $contents = $this->buildBackendWorktreeEnvLocalContents();
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Would write file: ' . $this->git->toRelativeProjectPath($targetPath));
            return;
        }

        $this->fs->writeFilePath($targetPath, $contents);
    }

    private function buildBackendWorktreeEnvLocalContents(): string
    {
        $envFile = $this->projectRoot . '/.env';
        try {
            $content = $this->fs->getFileContents($envFile);
        } catch (\Exception $e) {
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

    private function releaseBranchFromOtherWorktrees(string $branch, string $keepWorktree): void
    {
        $output = $this->git->listWorktreesPorcelain();
        $blocks = preg_split('/\n\n/', $output) ?: [];

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

            if ($path === null || $ref !== $branch || $path === $keepWorktree) {
                continue;
            }

            if ($this->git->hasLocalChanges($path)) {
                throw new \RuntimeException("Branch {$branch} is still active in a dirty worktree: {$path}");
            }

            if (!str_starts_with($path, $this->projectRoot . '/.worktrees/')) {
                throw new \RuntimeException("Branch {$branch} is active in a non-managed worktree: {$path}");
            }

            $this->git->removeWorktreeForce($path);
        }
    }

    /**
     * @return array<int, array{path: string, branch: string|null}>
     */
    private function listWorktreeBranchBindings(): array
    {
        $blocks = $this->fetchGitWorktreeBlocks();
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
        foreach ($this->listWorktreeBranchBindings() as $binding) {
            if ($binding['path'] !== $path) {
                continue;
            }

            return $binding['branch'];
        }

        return null;
    }

    /**
     * @return array<string, ActiveEntryReference>
     */
    private function fetchActiveEntriesByBranch(BacklogBoard $board): array
    {
        $features = [];
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            $branch = $entry->getBranch() ?? '';
            $feature = $entry->getFeature() ?? '';
            $agent = $entry->getAgent() ?? '';
            if ($branch === '' || $feature === '' || $agent === '') {
                continue;
            }

            $features[$branch] = new ActiveEntryReference($feature, $agent, $branch);
        }

        return $features;
    }

    /**
     * @return array<string, ActiveEntryReference>
     */
    private function fetchActiveEntriesByAgent(BacklogBoard $board): array
    {
        $entries = [];
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            $agent = $entry->getAgent() ?? '';
            $feature = $entry->getFeature() ?? '';
            if ($agent === '' || $feature === '') {
                continue;
            }

            $entries[$agent] = new ActiveEntryReference($feature, $agent, $entry->getBranch());
        }

        return $entries;
    }

    /**
     * @return array<int, array{path: string, branch: string|null, prunable: bool}>
     */
    private function fetchGitWorktreeBlocks(): array
    {
        $output = $this->git->listWorktreesPorcelain();
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

    private function checkIsManagedAgentWorktree(string $path): bool
    {
        return str_starts_with($path, $this->projectRoot . '/.worktrees/');
    }

    private function logVerbose(string $message): void
    {
        $this->console->logVerbose($message);
    }
}
