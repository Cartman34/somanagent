<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Service\AgentContextBuilder;
use SoManAgent\Script\Backlog\BacklogPaths;
use SoManAgent\Script\Backlog\Enum\SubmitMode;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\TextSlugger;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests for AgentContextBuilder.
 */
final class AgentContextBuilderTest
{
    private const FEATURE_SLUG = 'my-feature';

    private string $tmpDir;

    /**
     * Creates a temporary directory for test fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-ctx-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    /**
     * Removes the temporary directory on cleanup.
     */
    public function __destruct()
    {
        $this->rmdir($this->tmpDir);
    }

    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testFileIsCreated();
        $failed += $this->testContainsTitleSection();
        $failed += $this->testContainsWorkingDirectory();
        $failed += $this->testContainsNoActiveTaskWhenBoardAbsent();
        $failed += $this->testGitInternalsNotTouched();
        $failed += $this->testReviewerContextShowsNoReviewWhenBoardAbsent();
        $failed += $this->testReviewerContextShowsNoReviewWhenNoReviewingEntry();
        $failed += $this->testReviewerContextShowsCurrentReview();
        $failed += $this->testManagerContextShowsSessionInWP();
        $failed += $this->testManagerContextWorkingDirectoryRule();
        $failed += $this->testDeveloperContextWithActiveEntryHasWorkflow();
        $failed += $this->testReviewerContextWithActiveEntryHasWorkflow();
        $failed += $this->testDeveloperWorkflowSubmitModeUserWaitsForOperator();
        $failed += $this->testDeveloperWorkflowSubmitModeAgentAutoReviewRequest();

        return $failed;
    }

    private function testFileIsCreated(): int
    {
        $worktree = $this->tmpDir . '/wt-create-' . uniqid('', true);
        $builder = $this->makeBuilder();

        $path = $builder->build($worktree, 'd01', AgentRole::DEVELOPER);

        if (!is_file($path)) {
            echo "FAIL testFileIsCreated: context file not found at {$path}\n";
            $this->rmdir($worktree);
            return 1;
        }
        echo "OK testFileIsCreated\n";
        $this->rmdir($worktree);
        return 0;
    }

    private function testContainsTitleSection(): int
    {
        $worktree = $this->tmpDir . '/wt-title-' . uniqid('', true);
        $builder = $this->makeBuilder();

        $path = $builder->build($worktree, 'd42', AgentRole::MANAGER);
        $content = (string) file_get_contents($path);

        if (!str_contains($content, '# Agent d42 — manager')) {
            echo "FAIL testContainsTitleSection: title not found in context\n";
            $this->rmdir($worktree);
            return 1;
        }
        echo "OK testContainsTitleSection\n";
        $this->rmdir($worktree);
        return 0;
    }

    private function testContainsWorkingDirectory(): int
    {
        $worktree = $this->tmpDir . '/wt-wd-' . uniqid('', true);
        $builder = $this->makeBuilder();

        $path = $builder->build($worktree, 'd01', AgentRole::DEVELOPER);
        $content = (string) file_get_contents($path);

        if (!str_contains($content, $worktree)) {
            echo "FAIL testContainsWorkingDirectory: worktree path not in context\n";
            $this->rmdir($worktree);
            return 1;
        }
        echo "OK testContainsWorkingDirectory\n";
        $this->rmdir($worktree);
        return 0;
    }

    private function testContainsNoActiveTaskWhenBoardAbsent(): int
    {
        $worktree = $this->tmpDir . '/wt-notask-' . uniqid('', true);
        $builder = $this->makeBuilder();

        $path = $builder->build($worktree, 'd01', AgentRole::DEVELOPER);
        $content = (string) file_get_contents($path);

        if (!str_contains($content, 'No active task')) {
            echo "FAIL testContainsNoActiveTaskWhenBoardAbsent: 'No active task' not found\n";
            $this->rmdir($worktree);
            return 1;
        }
        echo "OK testContainsNoActiveTaskWhenBoardAbsent\n";
        $this->rmdir($worktree);
        return 0;
    }

    private function testGitInternalsNotTouched(): int
    {
        $worktree = $this->tmpDir . '/wt-no-git-write-' . uniqid('', true);
        mkdir($worktree . '/.git/info', 0755, true);
        // Pre-existing exclude with sentinel content the builder must not modify.
        $excludeFile = $worktree . '/.git/info/exclude';
        $sentinel = "# sentinel kept\n";
        file_put_contents($excludeFile, $sentinel);
        $excludeMtime = filemtime($excludeFile);
        clearstatcache();

        $builder = $this->makeBuilder();
        $builder->build($worktree, 'd01', AgentRole::DEVELOPER);

        clearstatcache();
        $actual = (string) file_get_contents($excludeFile);
        if ($actual !== $sentinel) {
            echo "FAIL testGitInternalsNotTouched: .git/info/exclude was modified\n";
            $this->rmdir($worktree);
            return 1;
        }
        if (filemtime($excludeFile) !== $excludeMtime) {
            echo "FAIL testGitInternalsNotTouched: .git/info/exclude mtime changed\n";
            $this->rmdir($worktree);
            return 1;
        }
        echo "OK testGitInternalsNotTouched\n";
        $this->rmdir($worktree);
        return 0;
    }

    private function testReviewerContextShowsNoReviewWhenBoardAbsent(): int
    {
        $worktree = $this->tmpDir . '/wt-reviewer-noboard-' . uniqid('', true);
        $builder = $this->makeBuilder();

        $path = $builder->build($worktree, 'r01', AgentRole::REVIEWER);
        $content = (string) file_get_contents($path);

        if (!str_contains($content, 'No review assigned')) {
            echo "FAIL testReviewerContextShowsNoReviewWhenBoardAbsent: expected 'No review assigned' in context\n";
            $this->rmdir($worktree);
            return 1;
        }
        echo "OK testReviewerContextShowsNoReviewWhenBoardAbsent\n";
        $this->rmdir($worktree);
        return 0;
    }

    private function testReviewerContextShowsNoReviewWhenNoReviewingEntry(): int
    {
        $projectRoot = $this->tmpDir . '/proj-reviewer-noentry-' . uniqid('', true);
        mkdir(BacklogPaths::directory($projectRoot), 0755, true);
        $boardPath = BacklogPaths::boardPath($projectRoot);
        file_put_contents($boardPath, $this->boardWithFeatureAtReview('some-feature', 'd01'));

        $worktree = $projectRoot . '/wt';

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $builder = new AgentContextBuilder($projectRoot, $boardPath, $boardService);

        $path = $builder->build($worktree, 'r01', AgentRole::REVIEWER);
        $content = (string) file_get_contents($path);

        if (!str_contains($content, 'No review assigned')) {
            echo "FAIL testReviewerContextShowsNoReviewWhenNoReviewingEntry: expected 'No review assigned' in context\n";
            $this->rmdir($projectRoot);
            return 1;
        }
        echo "OK testReviewerContextShowsNoReviewWhenNoReviewingEntry\n";
        $this->rmdir($projectRoot);
        return 0;
    }

    private function testReviewerContextShowsCurrentReview(): int
    {
        $projectRoot = $this->tmpDir . '/proj-reviewer-entry-' . uniqid('', true);
        mkdir(BacklogPaths::directory($projectRoot), 0755, true);
        $boardPath = BacklogPaths::boardPath($projectRoot);
        file_put_contents($boardPath, $this->boardWithFeatureAtReviewing(self::FEATURE_SLUG, 'd04', 'r01'));

        $worktree = $projectRoot . '/wt';

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $builder = new AgentContextBuilder($projectRoot, $boardPath, $boardService);

        $path = $builder->build($worktree, 'r01', AgentRole::REVIEWER);
        $content = (string) file_get_contents($path);

        $checks = [
            'Feature: my-feature' => str_contains($content, 'Feature: my-feature'),
            'Developer: d04'      => str_contains($content, 'Developer: d04'),
            'Reviewer: r01'       => str_contains($content, 'Reviewer: r01'),
            'Stage: reviewing'    => str_contains($content, 'Stage: reviewing'),
        ];

        foreach ($checks as $label => $ok) {
            if (!$ok) {
                echo "FAIL testReviewerContextShowsCurrentReview: missing '{$label}'\n";
                $this->rmdir($projectRoot);
                return 1;
            }
        }
        echo "OK testReviewerContextShowsCurrentReview\n";
        $this->rmdir($projectRoot);
        return 0;
    }

    private function testManagerContextShowsSessionInWP(): int
    {
        $worktree = $this->tmpDir . '/wt-manager-wp-' . uniqid('', true);
        $builder = $this->makeBuilder();

        $path = $builder->build($worktree, 'm01', AgentRole::MANAGER);
        $content = (string) file_get_contents($path);

        if (!str_contains($content, 'Manager session in WP')) {
            echo "FAIL testManagerContextShowsSessionInWP: 'Manager session in WP' not found in context\n";
            $this->rmdir($worktree);
            return 1;
        }
        echo "OK testManagerContextShowsSessionInWP\n";
        $this->rmdir($worktree);
        return 0;
    }

    private function testManagerContextWorkingDirectoryRule(): int
    {
        $worktree = $this->tmpDir . '/wt-manager-rule-' . uniqid('', true);
        $builder = $this->makeBuilder();

        $path = $builder->build($worktree, 'm01', AgentRole::MANAGER);
        $content = (string) file_get_contents($path);

        if (!str_contains($content, 'WP is the normal working directory')) {
            echo "FAIL testManagerContextWorkingDirectoryRule: WP rule not found in context\n";
            $this->rmdir($worktree);
            return 1;
        }
        echo "OK testManagerContextWorkingDirectoryRule\n";
        $this->rmdir($worktree);
        return 0;
    }

    private function testDeveloperContextWithActiveEntryHasWorkflow(): int
    {
        $projectRoot = $this->tmpDir . '/proj-dev-workflow-' . uniqid('', true);
        mkdir(BacklogPaths::directory($projectRoot), 0755, true);
        mkdir($projectRoot . '/doc/development', 0755, true);

        $boardPath = BacklogPaths::boardPath($projectRoot);
        file_put_contents($boardPath, $this->boardWithFeatureAtDevelopment(self::FEATURE_SLUG, 'd01'));

        // Minimal role doc with two keywords so we can assert `next` is removed but `submit` kept.
        file_put_contents(
            $projectRoot . '/doc/development/agent-developer.md',
            "# Dev\n\n## User Keywords\n\n### `next`\n\n1. Run work-start.\n\n### `submit`\n\n1. Run review-request.\n",
        );

        $worktree = $projectRoot . '/wt';

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $builder = new AgentContextBuilder($projectRoot, $boardPath, $boardService);

        $path = $builder->build($worktree, 'd01', AgentRole::DEVELOPER);
        $content = (string) file_get_contents($path);

        $this->rmdir($projectRoot);

        if (str_contains($content, '### `next`')) {
            echo "FAIL testDeveloperContextWithActiveEntryHasWorkflow: `next` keyword must be absent\n";
            return 1;
        }
        if (!str_contains($content, '## Workflow')) {
            echo "FAIL testDeveloperContextWithActiveEntryHasWorkflow: ## Workflow section must be present\n";
            return 1;
        }
        if (!str_contains($content, 'git add')) {
            echo "FAIL testDeveloperContextWithActiveEntryHasWorkflow: developer workflow steps must be present\n";
            return 1;
        }
        if (!str_contains($content, '### `submit`')) {
            echo "FAIL testDeveloperContextWithActiveEntryHasWorkflow: other keywords must remain (submit)\n";
            return 1;
        }

        echo "OK testDeveloperContextWithActiveEntryHasWorkflow\n";
        return 0;
    }

    private function testReviewerContextWithActiveEntryHasWorkflow(): int
    {
        $projectRoot = $this->tmpDir . '/proj-rev-workflow-' . uniqid('', true);
        mkdir(BacklogPaths::directory($projectRoot), 0755, true);
        mkdir($projectRoot . '/doc/development', 0755, true);

        $boardPath = BacklogPaths::boardPath($projectRoot);
        file_put_contents($boardPath, $this->boardWithFeatureAtReviewing(self::FEATURE_SLUG, 'd04', 'r01'));

        // Minimal role doc with two keywords so we can assert `review` is removed but `approve` kept.
        file_put_contents(
            $projectRoot . '/doc/development/agent-reviewer.md',
            "# Reviewer\n\n## User Keywords\n\n### `review`\n\n1. Run review-check.\n\n### `approve`\n\n1. Run review-approve.\n",
        );

        $worktree = $projectRoot . '/wt';

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $builder = new AgentContextBuilder($projectRoot, $boardPath, $boardService);

        $path = $builder->build($worktree, 'r01', AgentRole::REVIEWER);
        $content = (string) file_get_contents($path);

        $this->rmdir($projectRoot);

        if (str_contains($content, '### `review`')) {
            echo "FAIL testReviewerContextWithActiveEntryHasWorkflow: `review` keyword must be absent\n";
            return 1;
        }
        if (!str_contains($content, '## Workflow')) {
            echo "FAIL testReviewerContextWithActiveEntryHasWorkflow: ## Workflow section must be present\n";
            return 1;
        }
        if (!str_contains($content, 'review-check')) {
            echo "FAIL testReviewerContextWithActiveEntryHasWorkflow: reviewer workflow steps must be present\n";
            return 1;
        }
        if (!str_contains($content, '### `approve`')) {
            echo "FAIL testReviewerContextWithActiveEntryHasWorkflow: other keywords must remain (approve)\n";
            return 1;
        }

        echo "OK testReviewerContextWithActiveEntryHasWorkflow\n";
        return 0;
    }

    private function testDeveloperWorkflowSubmitModeUserWaitsForOperator(): int
    {
        $projectRoot = $this->tmpDir . '/proj-submit-user-' . uniqid('', true);
        mkdir(BacklogPaths::directory($projectRoot), 0755, true);
        mkdir($projectRoot . '/doc/development', 0755, true);

        $boardPath = BacklogPaths::boardPath($projectRoot);
        file_put_contents($boardPath, $this->boardWithFeatureAtDevelopment(self::FEATURE_SLUG, 'd01'));

        $worktree = $projectRoot . '/wt';
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $builder = new AgentContextBuilder($projectRoot, $boardPath, $boardService);

        $path = $builder->build($worktree, 'd01', AgentRole::DEVELOPER, SubmitMode::USER);
        $content = (string) file_get_contents($path);

        $this->rmdir($projectRoot);

        if (!str_contains($content, 'wait for the operator')) {
            echo "FAIL testDeveloperWorkflowSubmitModeUserWaitsForOperator: operator-wait instruction not found\n";
            return 1;
        }
        if (str_contains($content, 'review-request') && str_contains($content, 'immediately')) {
            echo "FAIL testDeveloperWorkflowSubmitModeUserWaitsForOperator: auto-review-request instruction must not appear in user mode\n";
            return 1;
        }

        echo "OK testDeveloperWorkflowSubmitModeUserWaitsForOperator\n";
        return 0;
    }

    private function testDeveloperWorkflowSubmitModeAgentAutoReviewRequest(): int
    {
        $projectRoot = $this->tmpDir . '/proj-submit-agent-' . uniqid('', true);
        mkdir(BacklogPaths::directory($projectRoot), 0755, true);
        mkdir($projectRoot . '/doc/development', 0755, true);

        $boardPath = BacklogPaths::boardPath($projectRoot);
        file_put_contents($boardPath, $this->boardWithFeatureAtDevelopment(self::FEATURE_SLUG, 'd01'));

        $worktree = $projectRoot . '/wt';
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $builder = new AgentContextBuilder($projectRoot, $boardPath, $boardService);

        $path = $builder->build($worktree, 'd01', AgentRole::DEVELOPER, SubmitMode::AGENT);
        $content = (string) file_get_contents($path);

        $this->rmdir($projectRoot);

        if (!str_contains($content, 'review-request')) {
            echo "FAIL testDeveloperWorkflowSubmitModeAgentAutoReviewRequest: review-request instruction not found\n";
            return 1;
        }
        if (!str_contains($content, 'immediately')) {
            echo "FAIL testDeveloperWorkflowSubmitModeAgentAutoReviewRequest: 'immediately' keyword not found in agent-mode instruction\n";
            return 1;
        }
        if (str_contains($content, 'wait for the operator')) {
            echo "FAIL testDeveloperWorkflowSubmitModeAgentAutoReviewRequest: operator-wait instruction must not appear in agent mode\n";
            return 1;
        }

        echo "OK testDeveloperWorkflowSubmitModeAgentAutoReviewRequest\n";
        return 0;
    }

    private function makeBuilder(): AgentContextBuilder
    {
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $boardPath = $this->tmpDir . '/nonexistent-board.md';

        return new AgentContextBuilder($this->tmpDir, $boardPath, $boardService);
    }

    private function boardWithFeatureAtDevelopment(string $feature, string $agent): string
    {
        return $this->boardYaml([
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => $feature,
                'developer' =>$agent,
                'branch' => 'feat/' . $feature,
                'base' => 'abc123def456',
                'pr' => 'none',
                'title' => 'Feature ' . $feature,
            ],
        ]);
    }

    private function boardWithFeatureAtReview(string $feature, string $agent): string
    {
        return $this->boardYaml([
            [
                'kind' => 'feature',
                'stage' => 'review',
                'feature' => $feature,
                'developer' =>$agent,
                'branch' => 'feat/' . $feature,
                'base' => 'abc123def456',
                'pr' => 'none',
                'title' => 'Feature ' . $feature,
            ],
        ]);
    }

    private function boardWithFeatureAtReviewing(string $feature, string $agent, string $reviewer): string
    {
        return $this->boardYaml([
            [
                'kind' => 'feature',
                'stage' => 'reviewing',
                'feature' => $feature,
                'developer' =>$agent,
                'reviewer' => $reviewer,
                'branch' => 'feat/' . $feature,
                'base' => 'abc123def456',
                'pr' => 'none',
                'title' => 'Feature ' . $feature,
            ],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $activeEntries
     */
    private function boardYaml(array $activeEntries): string
    {
        $order = ['kind', 'stage', 'feature', 'task', 'developer', 'reviewer', 'branch', 'feature-branch', 'base', 'pr', 'blocked', 'type'];
        $active = [];
        foreach ($activeEntries as $entry) {
            $item = [];
            foreach ($order as $key) {
                if (array_key_exists($key, $entry)) {
                    $item[$key] = $entry[$key];
                }
            }
            $item['title'] = $entry['title'] ?? ($entry['feature'] ?? '');
            $active[] = $item;
        }

        return Yaml::dump([
            'version' => 1,
            'todo' => [],
            'active' => $active,
        ], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
