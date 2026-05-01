<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog;

use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\TextSlugger;
use SoManAgent\Script\Service\GitService;

/**
 * Test driver for backlog script workflow testing
 *
 * Provides a fluent interface to execute backlog commands and verify test outcomes.
 */
final class BacklogScriptTestDriver
{
    private BacklogScriptTestContext $context;
    private ConsoleClient $consoleClient;
    private Console $console;

    /**
     * @param BacklogScriptTestContext $context Test context containing configuration
     * @param ConsoleClient $consoleClient Console client for command execution
     * @param Console $console Console instance for output
     */
    public function __construct(BacklogScriptTestContext $context, ConsoleClient $consoleClient, Console $console)
    {
        $this->context = $context;
        $this->consoleClient = $consoleClient;
        $this->console = $console;
    }

    /**
     * Initialize test artifacts
     */
    public function initializeArtifacts(): void
    {
        $this->resetArtifacts();
    }

    /**
     * Reset test artifacts to clean state
     */
    public function resetArtifacts(): void
    {
        $this->writeFile($this->context->boardPath, <<<MD
# Backlog board

## Usage rules

- Temporary test file for scripts/test-backlog-workflow.php.
- Do not use this file as a production backlog.

## To do

## In progress

## Suggestions

MD);

        $this->writeFile($this->context->reviewPath, <<<MD
# Current review

## Usage rules

- Temporary test file for scripts/test-backlog-workflow.php.
- Do not use this file as a production review.

## Current review

No review in progress.

MD);
    }

    /**
     * Finalize and cleanup test artifacts
     */
    public function finalizeArtifacts(): void
    {
        $this->cleanupTrackedResources();

        if ($this->context->keepArtifacts) {
            $this->console->line(sprintf(
                'Kept test artifacts: %s and %s',
                $this->relativePath($this->context->boardPath),
                $this->relativePath($this->context->reviewPath),
            ));

            return;
        }

        if (is_file($this->context->boardPath)) {
            unlink($this->context->boardPath);
        }
        if (is_file($this->context->reviewPath)) {
            unlink($this->context->reviewPath);
        }
    }

    /**
     * Run help command checks
     */
    public function runHelpChecks(): void
    {
        $this->assertOutputContains($this->runBacklog([]), 'Commands:');
        $this->assertOutputContains($this->runBacklog(['help']), 'Commands:');
        $this->assertOutputContains($this->runBacklog(['help', 'status']), 'status');
        $this->assertOutputContains($this->runBacklog(['help', 'review-next']), 'review-next');
        $this->assertOutputContains($this->runBacklog(['help', 'feature-start']), 'feature-start');
        $this->assertOutputContains($this->runBacklog(['feature-start', '--help']), 'feature-start');
    }

    /**
     * Run option equals syntax checks
     */
    public function runOptionEqualsChecks(): void
    {
        $this->assertOutputContains($this->runBacklog(['status', '--agent=' . $this->context->agentPrimary]), '[Task]');

        $bodyFile = $this->createBodyFile('test-option-equals-body.md', ['Missing task should fail after parsing body-file.']);
        $this->assertBacklogFails(
            ['task-review-reject', 'missing-feature/missing-task', '--body-file=' . $bodyFile],
            'Task not found: missing-feature/missing-task',
        );
    }

    /**
     * @param string $text Task text to create
     */
    public function createTodoTask(string $text): void
    {
        $this->runBacklog(['task-create', $text]);
    }

    /**
     * Remove the first todo task
     */
    public function removeFirstTodoTask(): void
    {
        $this->runBacklog(['task-remove', '1']);
    }

    /**
     * @param string $needle String that should be present in todo list output
     */
    public function assertTodoContains(string $needle): void
    {
        $this->assertOutputContains($this->runBacklog(['task-todo-list']), $needle);
    }

    /**
     * @param string $agent Agent identifier for the worktree
     * @return string Command output from feature-start
     */
    public function startNextFeature(string $agent): string
    {
        $worktreePath = $this->managedWorktreePath($agent);
        if ((is_dir($worktreePath) || is_file($worktreePath)) && !$this->context->hasWorktree($worktreePath)) {
            throw new \RuntimeException(sprintf(
                'Refusing to use pre-existing unmanaged test worktree: %s',
                $this->relativePath($worktreePath),
            ));
        }

        $output = $this->runBacklog(['feature-start', '--agent', $agent]);
        $this->context->recordWorktree($worktreePath);

        return $output;
    }

    /**
     * @param string $output Feature start output to check
     * @param string $needle String that should be present in output
     */
    public function assertFeatureStartOutputContains(string $output, string $needle): void
    {
        $this->assertOutputContains($output, $needle);
    }

    /**
     * @param string $feature Feature name to assign
     * @param string $agent Agent to assign the feature to
     */
    public function assignFeatureAsManager(string $feature, string $agent): void
    {
        $this->runBacklog(['feature-assign', $feature, '--agent', $agent], ['SOMANAGER_ROLE' => 'manager']);
    }

    /**
     * @param string $feature Feature name to unassign
     * @param string $agent Agent to unassign the feature from
     */
    public function unassignFeatureAsManager(string $feature, string $agent): void
    {
        $this->runBacklog(['feature-unassign', $feature, '--agent', $agent], ['SOMANAGER_ROLE' => 'manager']);
    }

    /**
     * @param string $agent Agent releasing the feature
     * @param string $feature Feature name to release
     */
    public function releaseFeature(string $agent, string $feature): void
    {
        $this->runBacklog(['feature-release', $feature, '--agent', $agent]);
    }

    /**
     * @param string $agent Agent attempting the release
     * @param string $feature Feature name to release
     * @param string $needle Expected error message
     */
    public function assertReleaseFeatureFails(string $agent, string $feature, string $needle): void
    {
        $this->assertBacklogFails(['feature-release', $feature, '--agent', $agent], $needle);
    }

    /**
     * @param string $feature Feature name to close
     */
    public function closeFeature(string $feature): void
    {
        $this->runBacklog(['feature-close', $feature]);
    }

    /**
     * @param string $featureOrAgent Feature name or agent identifier
     * @param bool $isAgent Whether the first parameter is an agent
     * @return string Status command output
     */
    public function status(string $featureOrAgent, bool $isAgent = false): string
    {
        return $isAgent
            ? $this->runBacklog(['status', '--agent', $featureOrAgent])
            : $this->runBacklog(['status', $featureOrAgent]);
    }

    /**
     * @param string $agent Agent performing the operation
     * @param string $featureText Feature text to add to queue
     */
    public function addQueuedTaskToCurrentFeature(string $agent, string $featureText): void
    {
        $this->runBacklog(['feature-task-add', '--agent', $agent, '--feature-text', $featureText]);
    }

    /**
     * @param string $agent Agent requesting the review
     * @param string $reference Task reference (feature/task)
     */
    public function requestTaskReview(string $agent, string $reference): void
    {
        $this->runBacklog(['task-review-request', '--agent', $agent, $reference]);
    }

    /**
     * @return string Output from review-next command
     */
    public function reviewNext(): string
    {
        return $this->runBacklog(['review-next']);
    }

    /**
     * @param string $reference Task reference to check
     */
    public function checkTaskReview(string $reference): void
    {
        $this->runBacklog(['task-review-check', $reference]);
    }

    /**
     * @param string $reference Task reference to reject
     * @param string $bodyFile Path to reject body file
     */
    public function rejectTaskReview(string $reference, string $bodyFile): void
    {
        $this->runBacklog(['task-review-reject', $reference, '--body-file', $bodyFile]);
    }

    /**
     * @param string $reference Task reference to reject
     * @param string $bodyFile Path to reject body file
     * @param string $needle Expected error message
     */
    public function assertTaskReviewRejectFails(string $reference, string $bodyFile, string $needle): void
    {
        $this->assertBacklogFails(['task-review-reject', $reference, '--body-file', $bodyFile], $needle);
    }

    public function rework(string $agent, string $reference = ''): void
    {
        $args = ['rework', '--agent', $agent];
        if ($reference !== '') {
            $args[] = $reference;
        }
        $this->runBacklog($args);
    }

    /**
     * @param string $reference Task reference to approve
     */
    public function approveTask(string $reference): void
    {
        $this->runBacklog(['task-review-approve', $reference]);
    }

    /**
     * @param string $reference Task reference to merge
     */
    public function mergeTask(string $reference): void
    {
        $this->runBacklog(['feature-task-merge', $reference]);
    }

    /**
     * @param string $agent Agent requesting the review
     * @param string $feature Feature name to review
     */
    public function requestFeatureReview(string $agent, string $feature): void
    {
        $this->runBacklog(['feature-review-request', '--agent', $agent, $feature]);
    }

    /**
     * @param string $feature Feature name to check
     */
    public function checkFeatureReview(string $feature): void
    {
        $this->runBacklog(['feature-review-check', $feature]);
    }

    /**
     * @param string $feature Feature name to reject
     * @param string $bodyFile Path to reject body file
     */
    public function rejectFeatureReview(string $feature, string $bodyFile): void
    {
        $this->runBacklog(['feature-review-reject', $feature, '--body-file', $bodyFile]);
    }

    /**
     * @param string $feature Feature name to reject
     * @param string $bodyFile Path to reject body file
     * @param string $needle Expected error message
     */
    public function assertFeatureReviewRejectFails(string $feature, string $bodyFile, string $needle): void
    {
        $this->assertBacklogFails(['feature-review-reject', $feature, '--body-file', $bodyFile], $needle);
    }

    /**
     * @param string $feature Feature name to approve
     * @param string $bodyFile Path to approve body file
     * @param string $needle Expected error message
     */
    public function assertFeatureReviewApproveFails(string $feature, string $bodyFile, string $needle): void
    {
        $this->assertBacklogFails(['feature-review-approve', $feature, '--body-file', $bodyFile], $needle);
    }

    public function approveFeature(string $feature, string $bodyFile): void
    {
        $this->runBacklog(['feature-review-approve', $feature, '--body-file', $bodyFile]);
        $entry = $this->requireFeatureEntry($feature);
        $branch = $entry->getBranch();
        if ($branch !== null && $branch !== '') {
            $this->context->recordRemoteBranch($branch);
        }
        $prNumber = $entry->getPr();
        if (!$this->context->dryRun && $prNumber === null) {
            throw new \RuntimeException('Expected feature-review-approve to record the pull request number.');
        }

        $this->context->setPullRequestNumber($prNumber !== null ? (int) $prNumber : null);
    }

    /**
     * @param string $agent Agent blocking the feature
     * @param string $feature Feature name to block
     */
    public function blockFeature(string $agent, string $feature): void
    {
        $this->runBacklog(['feature-block', '--agent', $agent, $feature]);
    }

    /**
     * @param string $agent Agent unblocking the feature
     * @param string $feature Feature name to unblock
     */
    public function unblockFeature(string $agent, string $feature): void
    {
        $this->runBacklog(['feature-unblock', '--agent', $agent, $feature]);
    }

    /**
     * @param string $feature Feature name to merge
     * @param string|null $bodyFile Path to merge body file
     */
    public function mergeFeature(string $feature, ?string $bodyFile = null): void
    {
        $arguments = ['feature-merge', $feature];
        if ($bodyFile !== null) {
            $arguments[] = '--body-file';
            $arguments[] = $bodyFile;
        }

        $this->runBacklog($arguments);
        $this->context->markPullRequestMerged();
    }

    public function assertFeatureMergeBodyFileWithoutValueFails(string $feature): void
    {
        $this->assertBacklogFails(
            ['feature-merge', $feature, '--body-file'],
            'Option --body-file requires a non-empty path when provided.',
        );
    }

    /**
     * @param string $agent Agent committing the change
     * @param string $feature Feature name
     * @param string $fileName Name of the file to create
     */
    public function commitFeatureChange(string $agent, string $feature, string $fileName): void
    {
        $worktreePath = $this->managedWorktreePath($agent);
        $relativeFilePath = $fileName;
        $absoluteFilePath = $worktreePath . '/' . $relativeFilePath;

        $parent = dirname($absoluteFilePath);
        if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
            throw new \RuntimeException("Unable to create worktree test directory: {$parent}");
        }

        $contents = sprintf(
            "feature=%s\nagent=%s\nts=%s\n",
            $feature,
            $agent,
            date('c'),
        );
        if (file_put_contents($absoluteFilePath, $contents) === false) {
            throw new \RuntimeException("Unable to write worktree test file: {$absoluteFilePath}");
        }

        $this->runGitInWorktree($worktreePath, sprintf('add %s', escapeshellarg($relativeFilePath)));
        $this->runGitInWorktree($worktreePath, sprintf(
            'commit -m %s',
            escapeshellarg(sprintf('[%s] Add workflow test artifact', $feature)),
        ));
    }

    /**
     * @param string $agent Agent committing the change
     * @param string $feature Feature name
     * @param string $fileName Name of the file to create and revert
     */
    public function commitAndRevertFeatureChange(string $agent, string $feature, string $fileName): void
    {
        $this->commitFeatureChange($agent, $feature, $fileName);

        $worktreePath = $this->managedWorktreePath($agent);
        $absoluteFilePath = $worktreePath . '/' . $fileName;
        if (!is_file($absoluteFilePath)) {
            throw new \RuntimeException("Expected worktree test file not found: {$absoluteFilePath}");
        }
        if (!unlink($absoluteFilePath)) {
            throw new \RuntimeException("Unable to remove worktree test file: {$absoluteFilePath}");
        }

        $this->runGitInWorktree($worktreePath, sprintf('add -A %s', escapeshellarg($fileName)));
        $this->runGitInWorktree($worktreePath, sprintf(
            'commit -m %s',
            escapeshellarg(sprintf('[%s] Revert workflow test artifact', $feature)),
        ));
    }

    /**
     * @param string $feature Feature name to track
     * @return string Branch name
     */
    public function trackFeatureBranch(string $feature): string
    {
        $entry = $this->requireFeatureEntry($feature);
        $branch = $entry->getBranch() ?? '';
        if ($branch === '') {
            throw new \RuntimeException("Feature {$feature} has no branch metadata in test backlog.");
        }

        $this->context->recordLocalBranch($branch);

        return $branch;
    }

    /**
     * @return string Created branch name
     */
    public function createRemoteTestBaseBranch(): string
    {
        $branch = sprintf('test/backlog-workflow-%s-%04d', date('Ymd-His'), random_int(1000, 9999));
        $this->runGitRoot(sprintf('fetch origin %s', GitService::MAIN_BRANCH));
        $this->runGitRoot(sprintf(
            'branch %s %s',
            escapeshellarg($branch),
            escapeshellarg(GitService::ORIGIN_REMOTE . '/' . GitService::MAIN_BRANCH),
        ));
        $this->context->recordLocalBranch($branch);
        $this->runGitRoot(sprintf('push -u origin %s', escapeshellarg($branch)));
        $this->context->recordRemoteBranch($branch);
        $this->context->setPrBaseBranch($branch);

        return $branch;
    }

    /**
     * @param string $feature Feature name that should exist
     */
    public function assertActiveFeatureExists(string $feature): void
    {
        $board = $this->board();
        if ($this->boardService()->findParentFeatureEntry($board, $feature) === null) {
            throw new \RuntimeException("Expected active feature not found in test backlog: {$feature}");
        }
    }

    /**
     * @param string $feature Feature name that should not exist
     */
    public function assertActiveFeatureMissing(string $feature): void
    {
        $board = $this->board();
        if ($this->boardService()->findParentFeatureEntry($board, $feature) !== null) {
            throw new \RuntimeException("Unexpected active feature still present in test backlog: {$feature}");
        }
    }

    /**
     * @param string $needle String that should be present in review content
     */
    public function assertReviewContains(string $needle): void
    {
        $contents = (string) file_get_contents($this->context->reviewPath);
        if (!str_contains($contents, $needle)) {
            throw new \RuntimeException("Expected review content not found: {$needle}");
        }
    }

    /**
     * @param string $needle String that should not be present in review content
     */
    public function assertReviewMissing(string $needle): void
    {
        $contents = (string) file_get_contents($this->context->reviewPath);
        if (str_contains($contents, $needle)) {
            throw new \RuntimeException("Unexpected review content still present: {$needle}");
        }
    }

    /**
     * @param string $featureOrAgent Feature name or agent identifier
     * @param string $needle String that should be present in status output
     * @param bool $isAgent Whether the first parameter is an agent
     */
    public function assertStatusContains(string $featureOrAgent, string $needle, bool $isAgent = false): void
    {
        $this->assertOutputContains($this->status($featureOrAgent, $isAgent), $needle);
    }

    /**
     * @param string $needle String that should be present in worktree list output
     */
    public function assertWorktreeListContains(string $needle): void
    {
        $this->assertOutputContains($this->runBacklog(['worktree-list']), $needle);
    }

    /**
     * @param string $agent Agent whose worktree to remove
     */
    public function removeManagedWorktree(string $agent): void
    {
        $path = $this->managedWorktreePath($agent);
        if (!$this->context->hasWorktree($path)) {
            throw new \RuntimeException(sprintf(
                'Refusing to remove unmanaged test worktree: %s',
                $this->relativePath($path),
            ));
        }

        if (!is_dir($path) && !is_file($path)) {
            return;
        }

        $this->runGitRoot(sprintf(
            'worktree remove --force %s',
            escapeshellarg($this->relativePath($path)),
        ));
    }

    /**
     * @param string $agent Agent whose worktree to restore
     */
    public function restoreWorktree(string $agent): void
    {
        $this->runBacklog(['worktree-restore', '--agent', $agent]);
        $path = $this->managedWorktreePath($agent);
        if (!is_dir($path) && !is_file($path)) {
            throw new \RuntimeException("Expected restored worktree not found: {$path}");
        }

        $this->context->recordWorktree($path);
    }

    /**
     * @param string $name File name for the body file
     * @param list<string> $lines Lines to write to the body file
     * @return string Path to the created body file
     */
    public function createBodyFile(string $name, array $lines): string
    {
        $path = $this->context->tmpDir . '/' . $name;
        $this->writeFile($path, implode("\n", $lines) . "\n");
        $this->context->recordTempFile($path);

        return $path;
    }

    /**
     * @param list<string> $arguments Backlog command arguments
     * @param array<string, string> $env Environment variables
     * @return string Command output
     */
    public function runBacklog(array $arguments, array $env = []): string
    {
        $command = $this->buildBacklogCommand($arguments, $env);
        [$code, $output] = $this->consoleClient->captureWithExitCode($command);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Backlog command failed with exit code %d: %s\n%s",
                $code,
                $command,
                $output,
            ));
        }

        return $output;
    }

    /**
     * @param list<string> $arguments Backlog command arguments
     */
    private function assertBacklogFails(array $arguments, string $needle): void
    {
        $command = $this->buildBacklogCommand($arguments);
        [$code, $output] = $this->consoleClient->captureWithExitCode($command);
        if ($code === 0) {
            throw new \RuntimeException("Expected backlog command to fail: {$command}");
        }
        $this->assertOutputContains($output, $needle);
    }

    /**
     * @param list<string> $arguments Backlog command arguments
     * @param array<string, string> $env Environment variables
     */
    private function buildBacklogCommand(array $arguments, array $env = []): string
    {
        $parts = [];
        foreach ($env as $key => $value) {
            $parts[] = sprintf('%s=%s', $key, escapeshellarg($value));
        }

        $parts[] = 'php scripts/backlog.php';
        foreach ($arguments as $argument) {
            $parts[] = escapeshellarg($argument);
        }

        $parts[] = '--test-mode';
        $parts[] = '--board-file';
        $parts[] = escapeshellarg($this->relativePath($this->context->boardPath));
        $parts[] = '--review-file';
        $parts[] = escapeshellarg($this->relativePath($this->context->reviewPath));
        $parts[] = '--worktree-dir';
        $parts[] = escapeshellarg($this->relativePath($this->context->worktreesRoot));
        if ($this->context->prBaseBranch() !== null) {
            $parts[] = '--pr-base-branch';
            $parts[] = escapeshellarg($this->context->prBaseBranch());
        }

        if ($this->context->dryRun) {
            $parts[] = '--dry-run';
        }
        if ($this->context->verbose) {
            $parts[] = '--verbose';
        }

        return implode(' ', $parts);
    }

    private function boardService(): BacklogBoardService
    {
        return new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
    }

    private function board(): BacklogBoard
    {
        return $this->boardService()->loadBoard($this->context->boardPath);
    }

    private function requireFeatureEntry(string $feature): \SoManAgent\Script\Backlog\Model\BoardEntry
    {
        $match = $this->boardService()->findParentFeatureEntry($this->board(), $feature);
        if ($match === null) {
            throw new \RuntimeException("Expected active feature not found in test backlog: {$feature}");
        }

        return $match->getEntry();
    }

    private function assertOutputContains(string $output, string $needle): void
    {
        if (!str_contains($output, $needle)) {
            throw new \RuntimeException("Expected command output to contain: {$needle}");
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("Unable to write test artifact: {$path}");
        }
    }

    private function runGitInWorktree(string $worktreePath, string $subCommand): void
    {
        $command = sprintf(
            'git -C %s %s',
            escapeshellarg($this->relativePath($worktreePath)),
            $subCommand,
        );
        [$code, $output] = $this->consoleClient->captureWithExitCode($command);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Git command failed with exit code %d: %s\n%s",
                $code,
                $command,
                $output,
            ));
        }
    }

    private function runGitRoot(string $subCommand): void
    {
        $command = sprintf('git %s', $subCommand);
        [$code, $output] = $this->consoleClient->captureWithExitCode($command);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Git command failed with exit code %d: %s\n%s",
                $code,
                $command,
                $output,
            ));
        }
    }

    private function cleanupTrackedResources(): void
    {
        if ($this->context->pullRequestNumber() !== null && !$this->context->isPullRequestMerged()) {
            $this->cleanupCommand(
                sprintf('close PR #%d', $this->context->pullRequestNumber()),
                sprintf('php scripts/github.php pr close %d', $this->context->pullRequestNumber()),
                ['GitHub API error 404', 'already closed'],
            );
        }

        foreach ($this->context->remoteBranches() as $branch) {
            $this->cleanupCommand(
                sprintf('delete remote branch %s', $branch),
                sprintf('git push origin --delete %s', escapeshellarg($branch)),
                ['remote ref does not exist', 'unable to delete'],
            );
        }

        foreach ($this->context->worktrees() as $worktree) {
            if (!is_dir($worktree)) {
                $this->console->line(sprintf('[cleanup] already clean: %s', $this->relativePath($worktree)));
                continue;
            }

            $this->cleanupCommand(
                sprintf('remove worktree %s', $this->relativePath($worktree)),
                sprintf('git worktree remove --force %s', escapeshellarg($this->relativePath($worktree))),
                ['is not a working tree'],
            );
        }

        foreach ($this->context->localBranches() as $branch) {
            $this->cleanupCommand(
                sprintf('delete local branch %s', $branch),
                sprintf('git branch -D %s', escapeshellarg($branch)),
                ['not found', 'branch \''],
            );
        }

        if (!$this->context->keepArtifacts) {
            foreach ($this->context->tempFiles() as $path) {
                if (!is_file($path)) {
                    $this->console->line(sprintf('[cleanup] already clean: %s', $this->relativePath($path)));
                    continue;
                }

                if (!unlink($path)) {
                    $this->console->warn(sprintf('[cleanup] failed: remove temp file %s', $this->relativePath($path)));
                    continue;
                }

                $this->console->line(sprintf('[cleanup] cleaned: %s', $this->relativePath($path)));
            }
        }
    }

    /**
     * @param list<string> $expectedMissingNeedles
     */
    private function cleanupCommand(string $label, string $command, array $expectedMissingNeedles = []): void
    {
        [$code, $output] = $this->consoleClient->captureWithExitCode($command);
        if ($code === 0) {
            $this->console->line(sprintf('[cleanup] cleaned: %s', $label));

            return;
        }

        foreach ($expectedMissingNeedles as $needle) {
            if ($needle !== '' && str_contains($output, $needle)) {
                $this->console->line(sprintf('[cleanup] already clean: %s', $label));

                return;
            }
        }

        $this->console->warn(sprintf('[cleanup] failed: %s', $label));
        if ($output !== '') {
            $this->console->line($output);
        }
    }

    public function managedWorktreePath(string $agent): string
    {
        return $this->context->worktreesRoot . '/' . $agent;
    }

    private function relativePath(string $path): string
    {
        return $this->consoleClient->toRelativeProjectPath($path);
    }
}
