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
use SoManAgent\Script\LocalWorkingDirectories;

/**
 * Handles managed backlog worktrees and local git orchestration.
 */
final class BacklogWorktreeService
{
    private const REVIEW_RESULT_FILE = 'local/backlog-review-result.txt';

    private string $projectRoot;
    private string $worktreesRoot;
    private bool $dryRun;
    private string $backendEnvLocalFallback;
    private BacklogBoardService $boardService;
    private ConsoleClient $console;
    private GitClient $git;
    private ProjectScriptClient $scripts;
    private FilesystemClientInterface $fs;

    /**
     * @param string $projectRoot
     * @param bool $dryRun
     * @param string $backendEnvLocalFallback
     * @param BacklogBoardService $boardService
     * @param ConsoleClient $console
     * @param GitClient $git
     * @param ProjectScriptClient $scripts
     * @param FilesystemClientInterface $fs
     */
    public function __construct(
        string $projectRoot,
        string $worktreesRoot,
        bool $dryRun,
        string $backendEnvLocalFallback,
        BacklogBoardService $boardService,
        ConsoleClient $console,
        GitClient $git,
        ProjectScriptClient $scripts,
        FilesystemClientInterface $fs
    ) {
        $this->projectRoot = $projectRoot;
        $this->worktreesRoot = $worktreesRoot;
        $this->dryRun = $dryRun;
        $this->backendEnvLocalFallback = $backendEnvLocalFallback;
        $this->boardService = $boardService;
        $this->console = $console;
        $this->git = $git;
        $this->scripts = $scripts;
        $this->fs = $fs;
    }

    /**
     * @param string $agent Agent code
     * @return string Absolute path to the agent worktree
     */
    public function getAgentWorktreePath(string $agent): string
    {
        return $this->worktreesRoot . '/' . $agent;
    }

    /**
     * Ensures the managed worktree for an agent exists and is clean.
     *
     * @param string $agent The agent name
     * @return string The worktree path
     */
    public function prepareAgentWorktree(string $agent): string
    {
        $path = $this->worktreesRoot . '/' . $agent;
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
     *
     * @param BoardEntry $entry The board entry
     * @return string The worktree path
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
     * @param string $featureBranch The feature branch name
     * @param string $feature The feature identifier
     * @return array{path: string, temporary: bool}
     */
    public function prepareFeatureMergeWorktree(string $featureBranch, string $feature): array
    {
        $existingPath = $this->findWorktreePathForBranch($featureBranch);
        if ($existingPath !== null) {
            if ($this->git->hasLocalChanges($existingPath)) {
                throw new \RuntimeException(sprintf(
                    'Feature branch %s is still dirty in worktree %s. Clean it before entry-merge.',
                    $featureBranch,
                    $existingPath,
                ));
            }

            return ['path' => $existingPath, 'temporary' => false];
        }

        $path = $this->worktreesRoot . '/merge-' . $feature;
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

    /**
     * @param string $path The worktree path to remove
     * @return void
     */
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

    /**
     * @param string $agent The agent name
     * @param string $taskBranch The task branch name
     * @param BacklogBoard $board The backlog board
     * @return void
     */
    public function cleanupMergedTaskWorktree(string $agent, string $taskBranch, BacklogBoard $board): void
    {
        if ($this->boardService->findTaskEntriesByAgent($board, $agent) !== []) {
            return;
        }

        $path = $this->worktreesRoot . '/' . $agent;
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

    /**
     * @param string $agent The agent name
     * @return void
     */
    public function removeAgentWorktreeForRestore(string $agent): void
    {
        $path = $this->worktreesRoot . '/' . $agent;
        if (!$this->fs->checkPathExists($path)) {
            return;
        }

        if ($this->git->hasLocalChanges($path)) {
            throw new \RuntimeException(sprintf(
                'Agent worktree %s is dirty. Commit or clean local changes before running worktree-restore --force.',
                $this->git->toRelativeProjectPath($path),
            ));
        }

        $this->git->removeWorktreeForce($path);
    }

    /**
     * @param string $branch The branch name
     * @param string $startPoint The branch start point
     * @return void
     */
    public function ensureLocalBranchExists(string $branch, string $startPoint): void
    {
        if ($this->git->localBranchExists($branch)) {
            return;
        }

        $this->git->createBranch($branch, $startPoint);
    }

    /**
     * @param string $branch The branch name
     * @param string $context The context for the requirement
     * @return void
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

        if ($this->git->localBranchExists($branch)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            '%s requires local branch %s to exist.',
            $context,
            $branch,
        ));
    }

    /**
     * @param string $worktree The worktree path
     * @param string $branch The branch name
     * @param bool $create Whether to create the branch if it doesn't exist
     * @param string $startPoint The branch start point (default: origin/main)
     * @return void
     */
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

    /**
     * @param string $branch The branch name
     * @return void
     */
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

    /**
     * @param BacklogBoard $board The backlog board
     * @return WorktreeClassification
     */
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
                $dirty = $this->git->hasLocalChanges($path);

                if ($agent === '') {
                    if ($dirty) {
                        $state = WorktreeState::DIRTY;
                        $action = WorktreeAction::MANUAL_REVIEW;
                    } else {
                        $state = WorktreeState::ORPHAN;
                        $action = WorktreeAction::CLEAN;
                    }
                } elseif ($path !== $this->worktreesRoot . '/' . $agent) {
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

    /**
     * @param BacklogBoard $board The backlog board
     * @return int The number of cleaned worktrees
     */
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

    /**
     * @param string $branch The branch name
     * @param BacklogBoard $board The backlog board
     * @return int The number of cleaned worktrees
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

            $this->git->removeWorktreeForce($item->getPath());
            $count++;
        }

        return $count;
    }

    /**
     * @param string $worktree The worktree path
     * @param string|null $base The base branch for review
     * @return void
     */
    public function runReviewScript(string $worktree, ?string $base = null): void
    {
        $this->logVerbose(($this->dryRun ? '[dry-run] Would run review in ' : 'Run review in ') . $this->git->toRelativeProjectPath($worktree));
        if ($this->dryRun) {
            return;
        }

        $arguments = $base !== null && $base !== ''
            ? sprintf('--base=%s', escapeshellarg($base))
            : '';
        [$code, $output] = $this->scripts->captureWithExitCode(AppScript::REVIEW, $arguments, projectRoot: $worktree);

        $resultPath = $worktree . '/' . self::REVIEW_RESULT_FILE;
        $this->fs->makeDirectory(dirname($resultPath));
        $this->fs->writeFilePath($resultPath, $output);
        echo rtrim($output) . "\n";

        if ($code !== 0) {
            throw new \RuntimeException("Review script failed with exit code {$code}.");
        }
    }

    /**
     * @param string $worktree The worktree path
     * @return string|null The saved review output, or null if no result has been saved yet
     */
    public function loadReviewResult(string $worktree): ?string
    {
        $path = $worktree . '/' . self::REVIEW_RESULT_FILE;

        return $this->fs->isFile($path) ? $this->fs->getFileContents($path) : null;
    }

    private function ensureWorktreeRuntimeState(string $worktree, bool $created): void
    {
        if (!$this->dryRun) {
            LocalWorkingDirectories::ensure($worktree, $this->fs);
        }
        $this->ensureWorktreeRuntimeIgnores($worktree);
        foreach ($this->fetchCopiedWorktreePaths() as $relativePath => $sourcePath) {
            if (!$this->fs->checkPathExists($sourcePath)) {
                throw new \RuntimeException("Missing dependency source in WP: {$sourcePath}");
            }
            $witness = $this->fetchRuntimeWitnessPaths()[$relativePath] ?? null;
            if ($witness !== null && !$this->fs->isFile($sourcePath . '/' . $witness)) {
                throw new \RuntimeException("Missing dependency witness in WP: {$sourcePath}/{$witness}");
            }

            $targetPath = $worktree . '/' . $relativePath;
            $parent = preg_replace('/\/[^\/]+$/', '', $targetPath);
            if ($parent === null) {
                throw new \RuntimeException("Unable to resolve parent directory for {$targetPath}");
            }
            if (!$this->fs->isDirectory($parent)) {
                if ($this->dryRun) {
                    $this->logVerbose('[dry-run] Would create directory: ' . $this->git->toRelativeProjectPath($parent));
                    continue;
                }
                $this->fs->makeDirectory($parent);
            }

            if (!$created && $this->fs->checkPathExists($targetPath)) {
                if ($this->checkRuntimePathIsValid($worktree, $relativePath)) {
                    continue;
                }

                $this->logVerbose(sprintf(
                    'Runtime dependency path is incomplete, replacing it: %s',
                    $this->git->toRelativeProjectPath($targetPath),
                ));
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
        if ($parent === null) {
            throw new \RuntimeException("Unable to resolve parent directory for {$excludePath}");
        }
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

    /**
     * @return array<string, string>
     */
    private function fetchRuntimeWitnessPaths(): array
    {
        return [
            'scripts/vendor' => 'autoload.php',
            'backend/vendor' => 'autoload.php',
        ];
    }

    private function checkRuntimePathIsValid(string $worktree, string $relativePath): bool
    {
        $witness = $this->fetchRuntimeWitnessPaths()[$relativePath] ?? null;
        if ($witness === null) {
            return true;
        }

        return $this->fs->isFile($worktree . '/' . $relativePath . '/' . $witness);
    }

    private function replacePathWithCopy(string $sourcePath, string $targetPath): void
    {
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Would copy path: ' . $this->git->toRelativeProjectPath($sourcePath) . ' -> ' . $this->git->toRelativeProjectPath($targetPath));
            return;
        }

        if ($this->fs->checkPathExists($targetPath)) {
            $this->fs->removePath($targetPath);
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

            if (!str_starts_with($path, $this->worktreesRoot . '/')) {
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

            $bindings[] = ['path' => $path, 'branch' => $branch];
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
            if ($branch === '' || $feature === '') {
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
        return str_starts_with($path, $this->worktreesRoot . '/');
    }

    private function logVerbose(string $message): void
    {
        $this->console->logVerbose($message);
    }
}
