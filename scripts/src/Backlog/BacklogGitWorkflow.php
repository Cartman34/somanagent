<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\GitClient;
use SoManAgent\Script\Console;

/**
 * Git workflow operations used by backlog commands above the low-level Git client.
 */
final class BacklogGitWorkflow
{
    public const MAIN_BRANCH = 'main';
    public const ORIGIN_REMOTE = 'origin';

    private bool $dryRun;
    private ConsoleClient $consoleClient;
    private Console $console;
    private GitClient $git;
    private PullRequestService $pullRequestService;

    /** @var callable(string): void */
    private $verboseLogger;

    public function __construct(
        bool $dryRun,
        ConsoleClient $consoleClient,
        Console $console,
        GitClient $git,
        PullRequestService $pullRequestService,
        callable $verboseLogger
    ) {
        $this->dryRun = $dryRun;
        $this->consoleClient = $consoleClient;
        $this->console = $console;
        $this->git = $git;
        $this->pullRequestService = $pullRequestService;
        $this->verboseLogger = $verboseLogger;
    }

    public function originMainHead(): string
    {
        return $this->branchHead(self::ORIGIN_REMOTE . '/' . self::MAIN_BRANCH);
    }

    public function branchHead(string $branch): string
    {
        return $this->git->branchHead($branch);
    }

    public function localBranchExists(?string $branch): bool
    {
        $branch = BoardEntry::parseEmptyString($branch);
        if ($branch === null) {
            return false;
        }

        return $this->git->localBranchExists($branch);
    }

    public function remoteBranchExists(string $branch): bool
    {
        return $this->git->remoteBranchExists(self::ORIGIN_REMOTE, $branch);
    }

    public function deleteLocalBranchIfExists(?string $branch): void
    {
        if (!$this->localBranchExists($branch)) {
            return;
        }

        $this->git->deleteLocalBranch((string) $branch);
    }

    public function deleteRemoteBranch(string $branch): void
    {
        $this->git->deleteRemoteBranch(self::ORIGIN_REMOTE, $branch);
    }

    public function mergeBranchInPath(string $path, string $branch, string $message): void
    {
        $this->git->mergeBranchInPath($path, $branch, $message);
    }

    public function branchHasNoDevelopment(string $base, string $branch): bool
    {
        return $this->git->countCommitsAhead($base, $branch) === 0;
    }

    public function pushBranchIfAhead(string $branch): void
    {
        if (!$this->localBranchExists($branch)) {
            return;
        }

        if (!$this->remoteBranchExists($branch)) {
            $this->pullRequestService->pushBranchAndWaitForRemoteVisibility($branch);

            return;
        }

        $ahead = $this->git->countCommitsAhead(self::ORIGIN_REMOTE . '/' . $branch, $branch);

        if ($ahead !== 0) {
            $this->pullRequestService->pushBranchAndWaitForRemoteVisibility($branch);
        }
    }

    public function workspaceHasLocalChanges(): bool
    {
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Inspect workspace changes');

            return trim($this->consoleClient->capture('git status --short')) !== '';
        }

        return $this->git->hasLocalChanges();
    }

    public function workspaceCurrentBranch(): string
    {
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Inspect workspace branch');

            return trim($this->consoleClient->capture('git branch --show-current'));
        }

        return $this->git->currentBranch();
    }

    public function updateMainBeforeFeatureStart(): void
    {
        if ($this->workspaceCurrentBranch() !== self::MAIN_BRANCH) {
            // Complex refspec fetch, easier to keep as runNetwork or add a specific method.
            // Using runNetwork here is acceptable for a specific refspec.
            $this->git->runNetwork(sprintf(
                'git fetch %s %s:%s',
                self::ORIGIN_REMOTE,
                self::MAIN_BRANCH,
                self::MAIN_BRANCH,
            ));

            return;
        }

        $this->updateLocalMainInWorkspaceWithWarning('feature-start');
    }

    public function handleMergeBaseAfterPrMerge(string $targetBaseBranch, string $context): bool
    {
        if ($targetBaseBranch !== self::MAIN_BRANCH) {
            $this->git->runNetwork(sprintf(
                'git fetch %s %s:%s',
                self::ORIGIN_REMOTE,
                escapeshellarg($targetBaseBranch),
                escapeshellarg($targetBaseBranch),
            ));

            return true;
        }

        if ($this->workspaceCurrentBranch() === self::MAIN_BRANCH) {
            $this->updateLocalMainInWorkspaceWithWarning($context);

            return true;
        }

        if ($this->workspaceHasLocalChanges()) {
            $this->git->runNetwork(sprintf(
                'git fetch %s %s:%s',
                self::ORIGIN_REMOTE,
                self::MAIN_BRANCH,
                self::MAIN_BRANCH,
            ));

            return true;
        }

        $this->git->checkoutAndPull(self::MAIN_BRANCH);

        return false;
    }

    /**
     * @return array<string>
     */
    public function changedFiles(string $base, string $branch): array
    {
        return $this->git->getChangedFiles($base, $branch);
    }

    private function updateLocalMainInWorkspaceWithWarning(string $context): void
    {
        try {
            $this->git->pullFastForwardOnly();
        } catch (\RuntimeException $exception) {
            $this->console->warn(sprintf(
                'Unable to update local main in WP during %s; continuing with the current local main.',
                $context,
            ));
            $this->logVerbose('Main update warning detail: ' . $exception->getMessage());
        }
    }

    private function logVerbose(string $message): void
    {
        ($this->verboseLogger)($message);
    }
}
