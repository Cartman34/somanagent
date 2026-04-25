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
    private PullRequestManager $pullRequests;

    /** @var callable(string): void */
    private $verboseLogger;

    public function __construct(
        bool $dryRun,
        ConsoleClient $consoleClient,
        Console $console,
        GitClient $git,
        PullRequestManager $pullRequests,
        callable $verboseLogger,
    ) {
        $this->dryRun = $dryRun;
        $this->consoleClient = $consoleClient;
        $this->console = $console;
        $this->git = $git;
        $this->pullRequests = $pullRequests;
        $this->verboseLogger = $verboseLogger;
    }

    public function originMainHead(): string
    {
        return $this->branchHead(self::ORIGIN_REMOTE . '/' . self::MAIN_BRANCH);
    }

    public function branchHead(string $branch): string
    {
        return trim($this->git->capture(sprintf(
            'git rev-parse %s',
            escapeshellarg($branch),
        )));
    }

    public function localBranchExists(?string $branch): bool
    {
        $branch = BoardEntry::parseEmptyString($branch);
        if ($branch === null) {
            return false;
        }

        return $this->git->succeeds(sprintf(
            'git show-ref --verify --quiet %s',
            escapeshellarg('refs/heads/' . $branch),
        ));
    }

    public function remoteBranchExists(string $branch): bool
    {
        return $this->git->succeeds(sprintf(
            'git show-ref --verify --quiet %s',
            escapeshellarg('refs/remotes/' . self::ORIGIN_REMOTE . '/' . $branch),
        ));
    }

    public function deleteLocalBranchIfExists(?string $branch): void
    {
        if (!$this->localBranchExists($branch)) {
            return;
        }

        $this->git->run(sprintf('git branch -D %s', escapeshellarg((string) $branch)));
    }

    public function deleteRemoteBranch(string $branch): void
    {
        $this->git->run(sprintf(
            'git push %s --delete %s',
            self::ORIGIN_REMOTE,
            escapeshellarg($branch),
        ));
    }

    public function mergeBranchInPath(string $path, string $branch, string $message): void
    {
        $this->git->run($this->git->inPath(
            $path,
            sprintf(
                'merge --no-ff %s -m %s',
                escapeshellarg($branch),
                escapeshellarg($message),
            ),
        ));
    }

    public function branchHasNoDevelopment(string $base, string $branch): bool
    {
        $ahead = trim($this->git->capture(sprintf(
            'git rev-list --count %s..%s',
            escapeshellarg($base),
            escapeshellarg($branch),
        )));

        return $ahead === '0';
    }

    public function pushBranchIfAhead(string $branch): void
    {
        if (!$this->localBranchExists($branch)) {
            return;
        }

        if (!$this->remoteBranchExists($branch)) {
            $this->pullRequests->pushBranchAndWaitForRemoteVisibility($branch);

            return;
        }

        $ahead = trim($this->git->capture(sprintf(
            'git rev-list --count %s..%s',
            escapeshellarg(self::ORIGIN_REMOTE . '/' . $branch),
            escapeshellarg($branch),
        )));

        if ($ahead !== '0') {
            $this->pullRequests->pushBranchAndWaitForRemoteVisibility($branch);
        }
    }

    public function workspaceHasLocalChanges(): bool
    {
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Inspect workspace changes: git status --short');

            return trim($this->consoleClient->capture('git status --short')) !== '';
        }

        return trim($this->git->capture('git status --short')) !== '';
    }

    public function workspaceCurrentBranch(): string
    {
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Inspect workspace branch: git branch --show-current');

            return trim($this->consoleClient->capture('git branch --show-current'));
        }

        return trim($this->git->capture('git branch --show-current'));
    }

    public function updateMainBeforeFeatureStart(): void
    {
        if ($this->workspaceCurrentBranch() !== self::MAIN_BRANCH) {
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

        $this->git->run('git checkout ' . self::MAIN_BRANCH);
        $this->git->run('git pull');

        return false;
    }

    /**
     * @return array<string>
     */
    public function changedFiles(string $base, string $branch): array
    {
        return array_values(array_filter(explode("\n", trim($this->git->capture(sprintf(
            'git diff --name-only %s..%s',
            escapeshellarg($base),
            escapeshellarg($branch),
        ))))));
    }

    private function updateLocalMainInWorkspaceWithWarning(string $context): void
    {
        try {
            $this->git->runNetwork('git pull --ff-only');
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
