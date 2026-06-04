<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Runner;

use Sowapps\SoManAgent\Script\Client\ConsoleClient;

/**
 * Deletes stale git branches that are safe to remove.
 *
 * Local cleanup (default): deletes local branches already merged into main.
 * A branch reported by `git branch --merged main` has its tip reachable from
 * main, so deleting it loses no commits. Uses `git branch -d` (not `-D`): Git
 * refuses any branch that is not actually merged, a second safety net on top of
 * the `--merged` filter. Refuses to run unless the current branch is main, and
 * never deletes main or the current branch.
 *
 * Remote cleanup is opt-in and reserved to a human operator (refused when
 * SOMANAGER_AGENT is set, i.e. when invoked from an agent session):
 * - `--remote`: delete remote branches merged into origin/main.
 * - `--remote-tests`: additionally delete remote test branches (feat/test-*,
 *   test/backlog-workflow-*). Only safe when no test campaign is running; the
 *   operator asserts this by passing the flag.
 *
 * The full deletion plan is printed and always requires an interactive "yes"
 * confirmation before anything is deleted; there is no bypass flag.
 */
final class GitCleanupBranchesRunner extends AbstractScriptRunner
{
    private const NAME = 'git-cleanup-branches';

    private const MAIN_BRANCH = 'main';

    private const REMOTE = 'origin';

    /**
     * Remote branch name patterns considered disposable test artifacts.
     *
     * @var list<string>
     */
    private const TEST_BRANCH_PATTERNS = [
        'origin/feat/test-*',
        'origin/test/backlog-workflow-*',
    ];

    protected function getName(): string
    {
        return self::NAME;
    }

    protected function getDescription(): string
    {
        return 'Delete stale git branches merged into main (and, for a human operator, on the remote).';
    }

    /**
     * @return array<string>
     */
    protected function getUsageExamples(): array
    {
        return [
            'php scripts/git-cleanup-branches.php',
            'php scripts/git-cleanup-branches.php --dry-run',
            'php scripts/git-cleanup-branches.php --remote',
            'php scripts/git-cleanup-branches.php --remote --remote-tests',
        ];
    }

    /**
     * @return array<array{name: string, description: string}>
     */
    protected function getOptions(): array
    {
        return array_merge(
            [
                ['name' => '--remote', 'description' => 'Also delete remote branches merged into origin/main (human operator only)'],
                ['name' => '--remote-tests', 'description' => 'Also delete remote test branches; assert no test campaign is running (human operator only)'],
            ],
            $this->getExecutionModeOptions(),
        );
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        [, $options] = $this->parseArgs($args);
        $this->configureExecutionModes($options);

        $remote = isset($options['remote']);
        $remoteTests = isset($options['remote-tests']);

        if (($remote || $remoteTests) && getenv('SOMANAGER_AGENT') !== false) {
            $this->console->fail(
                'Remote branch deletion is reserved to a human operator and cannot run from an agent session '
                . '(SOMANAGER_AGENT is set).',
            );
        }

        $console = new ConsoleClient(
            $this->projectRoot,
            false,
            $this->app,
            fn(string $message) => $this->verbose ? $this->console->line($message) : null,
        );

        $current = trim($console->capture('git branch --show-current'));
        if ($current !== self::MAIN_BRANCH) {
            $this->console->fail(sprintf(
                'Refusing to run on branch "%s": switch to %s first.',
                $current,
                self::MAIN_BRANCH,
            ));
        }

        $plan = $this->buildPlan($console, $current, $remote, $remoteTests);
        if ($plan === []) {
            $this->console->ok('No branch to delete.');

            return 0;
        }

        $this->displayPlan($plan);

        if ($this->dryRun) {
            $this->console->info('[dry-run] No branch deleted.');

            return 0;
        }

        if (!$this->console->confirm('This will delete the branches listed above.')) {
            $this->console->fail('Aborted.', 0);
        }

        return $this->executePlan($console, $plan) > 0 ? 1 : 0;
    }

    /**
     * Builds the deletion plan, fetching remote refs first when remote cleanup is requested.
     *
     * @param ConsoleClient $console Console client used to read git output
     * @param string $current Current branch name to exclude from local deletion
     * @param bool $remote Whether to include remote branches merged into origin/main
     * @param bool $remoteTests Whether to include remote test branches
     * @return list<array{scope: string, label: string, branches: list<string>}>
     */
    private function buildPlan(ConsoleClient $console, string $current, bool $remote, bool $remoteTests): array
    {
        if ($remote || $remoteTests) {
            $console->run(sprintf('git fetch --prune %s', escapeshellarg(self::REMOTE)));
        }

        $candidates = [
            ['scope' => 'local', 'label' => 'local merged', 'branches' => $this->collectMergedBranches($console, $current)],
        ];

        if ($remote) {
            $candidates[] = ['scope' => 'remote', 'label' => 'remote merged', 'branches' => $this->collectRemoteMergedBranches($console)];
        }

        if ($remoteTests) {
            $candidates[] = ['scope' => 'remote', 'label' => 'remote test', 'branches' => $this->collectRemoteTestBranches($console)];
        }

        return array_values(array_filter($candidates, static fn(array $group): bool => $group['branches'] !== []));
    }

    /**
     * Prints the deletion plan grouped by category.
     *
     * @param list<array{scope: string, label: string, branches: list<string>}> $plan Deletion plan
     */
    private function displayPlan(array $plan): void
    {
        foreach ($plan as $group) {
            $this->console->line(sprintf('%d %s branch(es) to delete:', count($group['branches']), $group['label']));
            foreach ($group['branches'] as $branch) {
                $display = $group['scope'] === 'remote' ? self::REMOTE . '/' . $branch : $branch;
                $this->console->line('  - ' . $display);
            }
        }
    }

    /**
     * Executes the deletion plan and returns the total number of failures.
     *
     * @param ConsoleClient $console Console client used to run git commands
     * @param list<array{scope: string, label: string, branches: list<string>}> $plan Deletion plan
     * @return int Total number of branches that failed to delete
     */
    private function executePlan(ConsoleClient $console, array $plan): int
    {
        $totalFailed = 0;
        foreach ($plan as $group) {
            $deleted = 0;
            $failed = 0;
            foreach ($group['branches'] as $branch) {
                $command = $group['scope'] === 'remote'
                    ? sprintf('git push %s --delete %s', escapeshellarg(self::REMOTE), escapeshellarg($branch))
                    : sprintf('git branch -d %s', escapeshellarg($branch));

                [$code, $output] = $console->captureWithExitCode($command);
                if ($code === 0) {
                    $deleted++;

                    continue;
                }

                $failed++;
                $this->console->warn(sprintf('failed to delete %s %s: %s', $group['label'], $branch, $output));
            }

            $this->console->ok(sprintf('%s: %d deleted, %d failed.', ucfirst($group['label']), $deleted, $failed));
            $totalFailed += $failed;
        }

        return $totalFailed;
    }

    /**
     * Returns local branches merged into main, excluding main and the current branch.
     *
     * @param ConsoleClient $console Console client used to read git output
     * @param string $current Current branch name to exclude from the result
     * @return list<string>
     */
    private function collectMergedBranches(ConsoleClient $console, string $current): array
    {
        $raw = $console->capture(sprintf('git branch --merged %s', escapeshellarg(self::MAIN_BRANCH)));

        $branches = [];
        foreach (explode("\n", $raw) as $line) {
            $name = trim(str_replace('*', '', $line));
            if ($name === '' || $name === self::MAIN_BRANCH || $name === $current) {
                continue;
            }

            $branches[] = $name;
        }

        return $branches;
    }

    /**
     * Returns remote branches merged into origin/main, without the origin/ prefix.
     *
     * @param ConsoleClient $console Console client used to read git output
     * @return list<string>
     */
    private function collectRemoteMergedBranches(ConsoleClient $console): array
    {
        $raw = $console->capture(sprintf(
            'git branch -r --merged %s',
            escapeshellarg(self::REMOTE . '/' . self::MAIN_BRANCH),
        ));

        return $this->normalizeRemoteBranchList($raw);
    }

    /**
     * Returns remote test branches matching the disposable test patterns, without the origin/ prefix.
     *
     * @param ConsoleClient $console Console client used to read git output
     * @return list<string>
     */
    private function collectRemoteTestBranches(ConsoleClient $console): array
    {
        $patterns = array_map(static fn(string $pattern): string => escapeshellarg($pattern), self::TEST_BRANCH_PATTERNS);
        $raw = $console->capture('git branch -r --list ' . implode(' ', $patterns));

        return $this->normalizeRemoteBranchList($raw);
    }

    /**
     * Parses `git branch -r` output into a clean list of branch names without the origin/ prefix.
     *
     * Skips the origin/main branch, the symbolic origin/HEAD entry, and empty lines.
     *
     * @param string $raw Raw `git branch -r` output
     * @return list<string>
     */
    private function normalizeRemoteBranchList(string $raw): array
    {
        $prefix = self::REMOTE . '/';

        $branches = [];
        foreach (explode("\n", $raw) as $line) {
            $name = trim($line);
            if ($name === '' || str_contains($name, '->')) {
                continue;
            }

            if (!str_starts_with($name, $prefix)) {
                continue;
            }

            $name = substr($name, strlen($prefix));
            if ($name === self::MAIN_BRANCH) {
                continue;
            }

            $branches[] = $name;
        }

        return $branches;
    }
}
