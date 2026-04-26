<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog;

use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\Backlog\BacklogBoard;

final class BacklogScriptTestDriver
{
    private BacklogScriptTestContext $context;
    private ConsoleClient $consoleClient;
    private Console $console;

    public function __construct(BacklogScriptTestContext $context, ConsoleClient $consoleClient, Console $console)
    {
        $this->context = $context;
        $this->consoleClient = $consoleClient;
        $this->console = $console;
    }

    public function initializeArtifacts(): void
    {
        $this->resetArtifacts();
    }

    public function resetArtifacts(): void
    {
        $this->writeFile($this->context->boardPath, <<<MD
# Tableau du backlog

## Règles d'usage

- Fichier de test temporaire pour scripts/test-backlog-workflow.php.
- Ne pas utiliser ce fichier comme backlog de production.

## À faire

## Traitement en cours

## Suggestions

MD);

        $this->writeFile($this->context->reviewPath, <<<MD
# Revue en cours

## Règles d'usage

- Fichier de test temporaire pour scripts/test-backlog-workflow.php.
- Ne pas utiliser ce fichier comme review de production.

## Revue en cours

Aucune review en cours.

MD);
    }

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

    public function runHelpChecks(): void
    {
        $this->assertOutputContains($this->runBacklog([]), 'Commands:');
        $this->assertOutputContains($this->runBacklog(['help']), 'Commands:');
        $this->assertOutputContains($this->runBacklog(['help', 'status']), 'status');
        $this->assertOutputContains($this->runBacklog(['help', 'review-next']), 'review-next');
        $this->assertOutputContains($this->runBacklog(['help', 'feature-start']), 'feature-start');
        $this->assertOutputContains($this->runBacklog(['feature-start', '--help']), 'feature-start');
    }

    public function createTodoTask(string $text): void
    {
        $this->runBacklog(['task-create', $text]);
    }

    public function removeFirstTodoTask(): void
    {
        $this->runBacklog(['task-remove', '1']);
    }

    public function assertTodoContains(string $needle): void
    {
        $this->assertOutputContains($this->runBacklog(['task-todo-list']), $needle);
    }

    public function startNextFeature(string $agent): void
    {
        $worktreePath = $this->managedWorktreePath($agent);
        if ((is_dir($worktreePath) || is_file($worktreePath)) && !$this->context->hasWorktree($worktreePath)) {
            throw new \RuntimeException(sprintf(
                'Refusing to use pre-existing unmanaged test worktree: %s',
                $this->relativePath($worktreePath),
            ));
        }

        $this->runBacklog(['feature-start', '--agent', $agent]);
        $this->context->recordWorktree($worktreePath);
    }

    public function assignFeatureAsManager(string $feature, string $agent): void
    {
        $this->runBacklog(['feature-assign', $feature, '--agent', $agent], ['SOMANAGER_ROLE' => 'manager']);
    }

    public function unassignFeatureAsManager(string $feature, string $agent): void
    {
        $this->runBacklog(['feature-unassign', $feature, '--agent', $agent], ['SOMANAGER_ROLE' => 'manager']);
    }

    public function releaseFeature(string $agent, string $feature): void
    {
        $this->runBacklog(['feature-release', $feature, '--agent', $agent]);
    }

    public function closeFeature(string $feature): void
    {
        $this->runBacklog(['feature-close', $feature]);
    }

    public function status(string $featureOrAgent, bool $isAgent = false): string
    {
        return $isAgent
            ? $this->runBacklog(['status', '--agent', $featureOrAgent])
            : $this->runBacklog(['status', $featureOrAgent]);
    }

    public function addQueuedTaskToCurrentFeature(string $agent, string $featureText): void
    {
        $this->runBacklog(['feature-task-add', '--agent', $agent, '--feature-text', $featureText]);
    }

    public function requestTaskReview(string $agent, string $reference): void
    {
        $this->runBacklog(['task-review-request', '--agent', $agent, $reference]);
    }

    public function reviewNext(): string
    {
        return $this->runBacklog(['review-next']);
    }

    public function checkTaskReview(string $reference): void
    {
        $this->runBacklog(['task-review-check', $reference]);
    }

    public function rejectTaskReview(string $reference, string $bodyFile): void
    {
        $this->runBacklog(['task-review-reject', $reference, '--body-file', $bodyFile]);
    }

    public function reworkTask(string $agent, string $reference): void
    {
        $this->runBacklog(['task-rework', '--agent', $agent, $reference]);
    }

    public function approveTask(string $reference): void
    {
        $this->runBacklog(['task-review-approve', $reference]);
    }

    public function mergeTask(string $reference): void
    {
        $this->runBacklog(['feature-task-merge', $reference]);
    }

    public function requestFeatureReview(string $agent, string $feature): void
    {
        $this->runBacklog(['feature-review-request', '--agent', $agent, $feature]);
    }

    public function checkFeatureReview(string $feature): void
    {
        $this->runBacklog(['feature-review-check', $feature]);
    }

    public function rejectFeatureReview(string $feature, string $bodyFile): void
    {
        $this->runBacklog(['feature-review-reject', $feature, '--body-file', $bodyFile]);
    }

    public function reworkFeature(string $agent, string $feature): void
    {
        $this->runBacklog(['feature-rework', '--agent', $agent, $feature]);
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
        $this->context->setPullRequestNumber($prNumber !== null ? (int) $prNumber : null);
    }

    public function blockFeature(string $agent, string $feature): void
    {
        $this->runBacklog(['feature-block', '--agent', $agent, $feature]);
    }

    public function unblockFeature(string $agent, string $feature): void
    {
        $this->runBacklog(['feature-unblock', '--agent', $agent, $feature]);
    }

    public function mergeFeature(string $feature, string $bodyFile): void
    {
        $this->runBacklog(['feature-merge', $feature, '--body-file', $bodyFile]);
        $this->context->markPullRequestMerged();
    }

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

    public function createRemoteTestBaseBranch(): string
    {
        $branch = sprintf('test/backlog-workflow-%s-%04d', date('Ymd-His'), random_int(1000, 9999));
        $this->runGitRoot('fetch origin main:main');
        $this->runGitRoot(sprintf(
            'branch %s %s',
            escapeshellarg($branch),
            escapeshellarg('main'),
        ));
        $this->context->recordLocalBranch($branch);
        $this->runGitRoot(sprintf('push -u origin %s', escapeshellarg($branch)));
        $this->context->recordRemoteBranch($branch);
        $this->context->setPrBaseBranch($branch);

        return $branch;
    }

    public function assertActiveFeatureExists(string $feature): void
    {
        $board = $this->board();
        if ($board->findFeature($feature) === null) {
            throw new \RuntimeException("Expected active feature not found in test backlog: {$feature}");
        }
    }

    public function assertActiveFeatureMissing(string $feature): void
    {
        $board = $this->board();
        if ($board->findFeature($feature) !== null) {
            throw new \RuntimeException("Unexpected active feature still present in test backlog: {$feature}");
        }
    }

    public function assertReviewContains(string $needle): void
    {
        $contents = (string) file_get_contents($this->context->reviewPath);
        if (!str_contains($contents, $needle)) {
            throw new \RuntimeException("Expected review content not found: {$needle}");
        }
    }

    public function assertReviewMissing(string $needle): void
    {
        $contents = (string) file_get_contents($this->context->reviewPath);
        if (str_contains($contents, $needle)) {
            throw new \RuntimeException("Unexpected review content still present: {$needle}");
        }
    }

    public function assertStatusContains(string $featureOrAgent, string $needle, bool $isAgent = false): void
    {
        $this->assertOutputContains($this->status($featureOrAgent, $isAgent), $needle);
    }

    public function assertWorktreeListContains(string $needle): void
    {
        $this->assertOutputContains($this->runBacklog(['worktree-list']), $needle);
    }

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
     * @param array<string> $lines
     */
    public function createBodyFile(string $name, array $lines): string
    {
        $path = $this->context->tmpDir . '/' . $name;
        $this->writeFile($path, implode("\n", $lines) . "\n");
        $this->context->recordTempFile($path);

        return $path;
    }

    /**
     * @param array<string> $arguments
     * @param array<string, string> $env
     */
    public function runBacklog(array $arguments, array $env = []): string
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

        $command = implode(' ', $parts);
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

    private function board(): BacklogBoard
    {
        return new BacklogBoard($this->context->boardPath);
    }

    private function requireFeatureEntry(string $feature): \SoManAgent\Script\Backlog\BoardEntry
    {
        $match = $this->board()->findFeature($feature);
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
     * @param array<string> $expectedMissingNeedles
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

    private function managedWorktreePath(string $agent): string
    {
        return $this->context->projectRoot . '/.worktrees/' . $agent;
    }

    private function relativePath(string $path): string
    {
        return $this->consoleClient->toRelativeProjectPath($path);
    }
}
