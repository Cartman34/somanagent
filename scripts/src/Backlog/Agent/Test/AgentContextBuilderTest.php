<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Service\AgentContextBuilder;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\TextSlugger;

/**
 * Unit tests for AgentContextBuilder.
 */
final class AgentContextBuilderTest
{
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
        $failed += $this->testContextExcludeAdded();
        $failed += $this->testReviewerContextShowsNoReviewWhenBoardAbsent();
        $failed += $this->testReviewerContextShowsNoReviewWhenNoReviewingEntry();
        $failed += $this->testReviewerContextShowsCurrentReview();

        return $failed;
    }

    private function testFileIsCreated(): int
    {
        $worktree = $this->tmpDir . '/wt-create-' . uniqid('', true);
        mkdir($worktree . '/.git/info', 0755, true);
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
        mkdir($worktree . '/.git/info', 0755, true);
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
        mkdir($worktree . '/.git/info', 0755, true);
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
        mkdir($worktree . '/.git/info', 0755, true);
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

    private function testContextExcludeAdded(): int
    {
        $worktree = $this->tmpDir . '/wt-exclude-' . uniqid('', true);
        mkdir($worktree . '/.git/info', 0755, true);
        $builder = $this->makeBuilder();

        $builder->build($worktree, 'd01', AgentRole::DEVELOPER);

        $excludeFile = $worktree . '/.git/info/exclude';
        if (!is_file($excludeFile)) {
            echo "FAIL testContextExcludeAdded: exclude file not created\n";
            $this->rmdir($worktree);
            return 1;
        }
        $exclude = (string) file_get_contents($excludeFile);
        if (!str_contains($exclude, 'local/agent-context.md')) {
            echo "FAIL testContextExcludeAdded: pattern not in exclude file\n";
            $this->rmdir($worktree);
            return 1;
        }
        echo "OK testContextExcludeAdded\n";
        $this->rmdir($worktree);
        return 0;
    }

    private function testReviewerContextShowsNoReviewWhenBoardAbsent(): int
    {
        $worktree = $this->tmpDir . '/wt-reviewer-noboard-' . uniqid('', true);
        mkdir($worktree . '/.git/info', 0755, true);
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
        mkdir($projectRoot . '/local', 0755, true);
        $boardPath = $projectRoot . '/local/backlog-board.md';
        file_put_contents($boardPath, $this->boardWithFeatureAtReview('some-feature', 'd01'));

        $worktree = $projectRoot . '/wt';
        mkdir($worktree . '/.git/info', 0755, true);

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
        mkdir($projectRoot . '/local', 0755, true);
        $boardPath = $projectRoot . '/local/backlog-board.md';
        file_put_contents($boardPath, $this->boardWithFeatureAtReviewing('my-feature', 'd04', 'r01'));

        $worktree = $projectRoot . '/wt';
        mkdir($worktree . '/.git/info', 0755, true);

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

    private function makeBuilder(): AgentContextBuilder
    {
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $boardPath = $this->tmpDir . '/nonexistent-board.md';

        return new AgentContextBuilder($this->tmpDir, $boardPath, $boardService);
    }

    private function boardWithFeatureAtReview(string $feature, string $agent): string
    {
        return "# Tableau du backlog\n\n## To do\n\n## In progress\n\n" .
            "- Feature {$feature}\n" .
            "  meta:\n" .
            "    kind: feature\n" .
            "    stage: review\n" .
            "    feature: {$feature}\n" .
            "    agent: {$agent}\n" .
            "    branch: feat/{$feature}\n" .
            "    base: abc123def456\n" .
            "    pr: none\n\n" .
            "## Suggestions\n\n";
    }

    private function boardWithFeatureAtReviewing(string $feature, string $agent, string $reviewer): string
    {
        return "# Tableau du backlog\n\n## To do\n\n## In progress\n\n" .
            "- Feature {$feature}\n" .
            "  meta:\n" .
            "    kind: feature\n" .
            "    stage: reviewing\n" .
            "    feature: {$feature}\n" .
            "    agent: {$agent}\n" .
            "    reviewer: {$reviewer}\n" .
            "    branch: feat/{$feature}\n" .
            "    base: abc123def456\n" .
            "    pr: none\n\n" .
            "## Suggestions\n\n";
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
