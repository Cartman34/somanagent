<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;
use SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;
use SoManAgent\Script\Test\Backlog\Campaign\CampaignInterface;
use SoManAgent\Script\Test\Backlog\Campaign\FeatureReviewLifecycleCampaign;
use SoManAgent\Script\Test\Backlog\Campaign\HelpCampaign;
use SoManAgent\Script\Test\Backlog\Campaign\MutationLockCampaign;
use SoManAgent\Script\Test\Backlog\Campaign\ScopedTaskLifecycleCampaign;
use SoManAgent\Script\Test\Backlog\Campaign\TaskCreateFormatsCampaign;
use SoManAgent\Script\Test\Backlog\Campaign\TodoAndPlainFeatureLifecycleCampaign;
use SoManAgent\Script\Test\Backlog\Campaign\WorkStartTypePrefixCampaign;

/**
 * Runs sequential validation campaigns to test the backlog workflow script.
 */
final class TestBacklogWorkflowRunner extends AbstractScriptRunner
{
    public const NAME = 'test-backlog-workflow';

    protected function getName(): string
    {
        return self::NAME;
    }

    /** @var array<string, CampaignInterface>|null */
    private ?array $campaigns = null;

    protected function getDescription(): string
    {
        return 'Run sequential validation campaigns for scripts/backlog.php';
    }

    protected function getOptions(): array
    {
        return array_merge(
            [
                ['name' => '--campaign', 'description' => 'Campaign to run: help, todo-and-plain-feature-lifecycle, scoped-task-lifecycle, task-create-formats, work-start-type-prefix, feature-review-lifecycle, mutation-lock, or all'],
                ['name' => '--allow-remote', 'description' => 'Allow campaigns that push branches or create/merge GitHub PRs'],
                ['name' => '--keep-artifacts', 'description' => 'Keep temporary backlog/review files under local/tmp/ after execution'],
            ],
            $this->getExecutionModeOptions(),
        );
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/test-backlog-workflow.php',
            'php scripts/test-backlog-workflow.php --campaign scoped-task-lifecycle',
            'php scripts/test-backlog-workflow.php --allow-remote --campaign feature-review-lifecycle',
        ];
    }

    /**
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        [$commandArgs, $options] = $this->parseArgs(array_values($args));
        $this->configureExecutionModes($options);

        if ($commandArgs !== []) {
            throw new \RuntimeException('This script only accepts named options. Use --campaign=<name>.');
        }

        $rawCampaign = $options['campaign'] ?? 'all';
        if (is_array($rawCampaign)) {
            throw new \RuntimeException('Option --campaign cannot be repeated.');
        }
        $requestedCampaign = trim((string) $rawCampaign);
        $allowRemote = isset($options['allow-remote']);
        $keepArtifacts = isset($options['keep-artifacts']);
        $runToken = sprintf('%s-%04d', date('YmdHis'), random_int(1000, 9999));

        $testWorktreesParent = $this->projectRoot . '/local/test-worktrees';
        $this->sweepStaleTestWorktrees($testWorktreesParent);
        $this->assertNoStaleTestWorktrees($testWorktreesParent);

        $worktreesRoot = $testWorktreesParent . '/' . $runToken;
        if (!is_dir($worktreesRoot) && !mkdir($worktreesRoot, 0777, true) && !is_dir($worktreesRoot)) {
            throw new \RuntimeException("Unable to create temp worktrees directory: {$worktreesRoot}");
        }

        $context = new BacklogScriptTestContext(
            projectRoot: $this->projectRoot,
            boardPath: $this->projectRoot . '/local/tmp/test-backlog-workflow-board.md',
            reviewPath: $this->projectRoot . '/local/tmp/test-backlog-workflow-review.md',
            tmpDir: $this->projectRoot . '/local/tmp',
            worktreesRoot: $worktreesRoot,
            allowRemote: $allowRemote,
            keepArtifacts: $keepArtifacts,
            dryRun: $this->dryRun,
            verbose: $this->verbose,
            agentPrimary: 'test-d01-' . $runToken,
            agentSecondary: 'test-d02-' . $runToken,
        );
        $driver = new BacklogScriptTestDriver(
            $context,
            new ConsoleClient(
                $this->projectRoot,
                $this->dryRun,
                $this->app,
                fn(string $message) => $this->logVerbose($message),
            ),
            $this->console,
        );

        try {
            foreach ($this->resolveCampaigns($requestedCampaign, $allowRemote) as $campaign) {
                $driver->resetArtifacts();
                $this->console->line(sprintf('[Campaign] %s', $campaign->getName()));
                $campaign->run($driver, $context);
            }
        } finally {
            $driver->finalizeArtifacts();
            if (!$keepArtifacts && is_dir($worktreesRoot)) {
                exec(sprintf('rm -rf %s', escapeshellarg($worktreesRoot)));
            }
        }

        $this->console->ok('Backlog workflow test campaign(s) completed.');

        return 0;
    }

    /**
     * @return array<int, CampaignInterface>
     */
    private function resolveCampaigns(string $requestedCampaign, bool $allowRemote): array
    {
        $campaigns = $this->campaignCatalog();

        if ($requestedCampaign === 'all') {
            $resolved = [
                $campaigns['help'],
                $campaigns['task-create-formats'],
                $campaigns['work-start-type-prefix'],
                $campaigns['todo-and-plain-feature-lifecycle'],
                $campaigns['scoped-task-lifecycle'],
            ];

            if (!$this->dryRun) {
                $resolved[] = $campaigns['mutation-lock'];
            } else {
                $this->console->warn('Skipping mutation-lock because --dry-run is set.');
            }

            if ($allowRemote) {
                $resolved[] = $campaigns['feature-review-lifecycle'];
            } else {
                $this->console->warn('Skipping feature-review-lifecycle because --allow-remote is not enabled.');
            }

            return $resolved;
        }

        if (!isset($campaigns[$requestedCampaign])) {
            throw new \RuntimeException("Unknown campaign: {$requestedCampaign}");
        }

        if ($requestedCampaign === 'feature-review-lifecycle' && !$allowRemote) {
            throw new \RuntimeException('feature-review-lifecycle requires --allow-remote.');
        }

        if ($requestedCampaign === 'mutation-lock' && $this->dryRun) {
            throw new \RuntimeException('mutation-lock campaign cannot run in dry-run mode: the lock is skipped when --dry-run is set.');
        }

        return [$campaigns[$requestedCampaign]];
    }

    /**
     * @return array<string, CampaignInterface>
     */
    private function campaignCatalog(): array
    {
        if ($this->campaigns === null) {
            $this->campaigns = [
                'help' => new HelpCampaign(),
                'task-create-formats' => new TaskCreateFormatsCampaign(),
                'work-start-type-prefix' => new WorkStartTypePrefixCampaign(),
                'todo-and-plain-feature-lifecycle' => new TodoAndPlainFeatureLifecycleCampaign(),
                'scoped-task-lifecycle' => new ScopedTaskLifecycleCampaign(),
                'mutation-lock' => new MutationLockCampaign(),
                'feature-review-lifecycle' => new FeatureReviewLifecycleCampaign(),
            ];
        }

        return $this->campaigns;
    }

    /**
     * Remove worktrees and branches left behind by interrupted test runs under testWorktreesParent.
     *
     * Called at startup so that a previous Ctrl+C / kill before the finally block does not leave
     * stale git worktrees registered for branches like feat/test-plain-feature-alpha, which would
     * cause the next work-start to fail with "Branch X is active in a non-managed worktree".
     */
    private function sweepStaleTestWorktrees(string $testWorktreesParent): void
    {
        if (!is_dir($testWorktreesParent)) {
            return;
        }

        exec('git worktree list --porcelain 2>&1', $lines, $code);
        if ($code !== 0) {
            $this->console->warn('[sweep] git worktree list failed; skipping stale test-worktree sweep');

            return;
        }

        /** @var array<string, string|null> $staleWorktrees path => branch|null */
        $staleWorktrees = [];
        $currentPath = null;
        $currentBranch = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $currentPath = substr($line, strlen('worktree '));
                $currentBranch = null;
            } elseif (str_starts_with($line, 'branch ')) {
                $currentBranch = substr($line, strlen('branch refs/heads/'));
            } elseif ($line === '' && $currentPath !== null) {
                if (str_starts_with($currentPath, $testWorktreesParent . '/')) {
                    $staleWorktrees[$currentPath] = $currentBranch;
                }
                $currentPath = null;
                $currentBranch = null;
            }
        }
        // Handle last entry when no trailing blank line is present
        if ($currentPath !== null && str_starts_with($currentPath, $testWorktreesParent . '/')) {
            $staleWorktrees[$currentPath] = $currentBranch;
        }

        if ($this->dryRun) {
            foreach ($staleWorktrees as $path => $branch) {
                $this->console->line(sprintf('[sweep][dry-run] would remove worktree %s (branch: %s)', $this->relativeProjectPath($path), $branch ?? '(detached)'));
            }

            return;
        }

        foreach ($staleWorktrees as $path => $branch) {
            $rmOut = [];
            exec(sprintf('git worktree remove --force %s 2>&1', escapeshellarg($path)), $rmOut, $rmCode);
            if ($rmCode === 0) {
                $this->console->line(sprintf('[sweep] removed worktree: %s', $this->relativeProjectPath($path)));
                if ($branch !== null && $branch !== '') {
                    $branchOut = [];
                    exec(sprintf('git branch -D %s 2>&1', escapeshellarg($branch)), $branchOut, $branchCode);
                    if ($branchCode === 0) {
                        $this->console->line(sprintf('[sweep] deleted branch: %s', $branch));
                    } else {
                        $this->console->warn(sprintf('[sweep] failed to delete branch %s: %s', $branch, implode(' ', $branchOut)));
                    }
                }
            } else {
                $this->console->warn(sprintf('[sweep] failed to remove worktree %s: %s', $this->relativeProjectPath($path), implode(' ', $rmOut)));
            }
        }

        if ($staleWorktrees !== []) {
            exec('git worktree prune 2>&1');
        }

        // Remove any leftover run directories whose worktrees were already deregistered or never created
        foreach (glob($testWorktreesParent . '/*') ?: [] as $entry) {
            if (!is_dir($entry)) {
                continue;
            }
            $rmdirOut = [];
            exec(sprintf('rm -rf %s 2>&1', escapeshellarg($entry)), $rmdirOut, $rmdirCode);
            if ($rmdirCode === 0) {
                $this->console->line(sprintf('[sweep] removed stale run dir: %s', $this->relativeProjectPath($entry)));
            } else {
                $this->console->warn(sprintf('[sweep] failed to remove stale run dir: %s', $this->relativeProjectPath($entry)));
            }
        }
    }

    /**
     * Assert that no stale test worktrees remain registered after the sweep.
     *
     * Fails fast with a diagnostic message so a broken sweep is visible immediately
     * rather than surfacing as a confusing "branch is active in a non-managed worktree" error.
     */
    private function assertNoStaleTestWorktrees(string $testWorktreesParent): void
    {
        if ($this->dryRun) {
            return;
        }

        exec('git worktree list --porcelain 2>&1', $lines, $code);
        if ($code !== 0) {
            return;
        }

        $remaining = [];
        $currentPath = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $currentPath = substr($line, strlen('worktree '));
            } elseif ($line === '' && $currentPath !== null) {
                if (str_starts_with($currentPath, $testWorktreesParent . '/')) {
                    $remaining[] = $this->relativeProjectPath($currentPath);
                }
                $currentPath = null;
            }
        }
        if ($currentPath !== null && str_starts_with($currentPath, $testWorktreesParent . '/')) {
            $remaining[] = $this->relativeProjectPath($currentPath);
        }

        if ($remaining !== []) {
            throw new \RuntimeException(sprintf(
                'Stale test worktrees remain after sweep — cannot start a new run safely. Remove manually: %s',
                implode(', ', $remaining),
            ));
        }
    }

    private function relativeProjectPath(string $path): string
    {
        $prefix = $this->projectRoot . '/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    private function logVerbose(string $message): void
    {
        if ($this->verbose) {
            $this->console->info($message);
        }
    }
}
