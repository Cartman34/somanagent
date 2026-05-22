#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run unit tests for scripts/backlog-agent.php classes
// Usage: php scripts/test-backlog-agent.php
// Usage: php scripts/test-backlog-agent.php --suite=AgentCodeServiceTest

require_once __DIR__ . '/src/bootstrap.php';

use SoManAgent\Script\Backlog\Agent\Test\AgentClientLauncherRegistryTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentCliOptionValidatorTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentCodeServiceTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentContextBuilderTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentListCommandTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentModelResolverTest;

use SoManAgent\Script\Backlog\Agent\Test\AgentDeveloperSelectorTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentReviewerSelectorTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentSessionServiceTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentSessionsCommandTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentLaunchPromptResolverTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentStartCommandManagerTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentStartCommandTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentWatchModeTest;
use SoManAgent\Script\Backlog\Agent\Test\EntryRebaseCommandTest;
use SoManAgent\Script\Backlog\Agent\Test\EntryRebaseServiceTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentStatusCommandTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentStopCommandTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentWhoamiCommandTest;
use SoManAgent\Script\Backlog\Agent\Test\BacklogAgentPruneCommandTest;
use SoManAgent\Script\Backlog\Agent\Test\BacklogBoardServiceReviewingTest;
use SoManAgent\Script\Backlog\Agent\Test\BoardYamlStorageTest;
use SoManAgent\Script\Backlog\Agent\Test\BodyFilePathResolverTest;
use SoManAgent\Script\Backlog\Agent\Test\BacklogAgentRunnerStrictOptionsTest;
use SoManAgent\Script\Backlog\Agent\Test\BacklogAgentRunnerWiringTest;
use SoManAgent\Script\Backlog\Agent\Test\ClaudeAgentLauncherTest;
use SoManAgent\Script\Backlog\Agent\Test\CodexAgentLauncherTest;
use SoManAgent\Script\Backlog\Agent\Test\DirectSessionDriverTest;
use SoManAgent\Script\Backlog\Agent\Test\GeminiAgentLauncherTest;
use SoManAgent\Script\Backlog\Agent\Test\LauncherFlagValidatorTest;
use SoManAgent\Script\Backlog\Agent\Test\OpenCodeAgentLauncherTest;
use SoManAgent\Script\Backlog\Agent\Test\SystemInteractiveProcessRunnerTest;
use SoManAgent\Script\Backlog\Agent\Test\TmuxSessionDriverTest;
use SoManAgent\Script\Backlog\Agent\Test\WorktreeScriptProxyTest;
use SoManAgent\Script\Backlog\Test\BacklogCommitGateCommandTest;
use SoManAgent\Script\Backlog\Test\BacklogPreCommitHookTest;
use SoManAgent\Script\Backlog\Test\BacklogReviewApproveCommandTest;
use SoManAgent\Script\Backlog\Test\BacklogReviewNextCommandTest;
use SoManAgent\Script\Backlog\Test\BacklogWorktreeServiceTest;
use SoManAgent\Script\Backlog\Test\PostMergeSessionStopperTest;
use SoManAgent\Script\Backlog\Test\ReviewResumeNotifierTest;
use SoManAgent\Script\Client\Test\GitClientTest;
use SoManAgent\Script\Console;

$console = Console::getInstance();

// Parse --suite option
$suite = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--suite=')) {
        $suite = substr($arg, 8);
    }
}

/** @var array<string, callable(): int> */
$suites = [
    'AgentCodeServiceTest' => static fn(): int => (new AgentCodeServiceTest())->run(),
    'AgentSessionServiceTest' => static fn(): int => (new AgentSessionServiceTest())->run(),
    'AgentStartCommandManagerTest' => static fn(): int => (new AgentStartCommandManagerTest())->run(),
    'AgentContextBuilderTest' => static fn(): int => (new AgentContextBuilderTest())->run(),
    'AgentDeveloperSelectorTest' => static fn(): int => (new AgentDeveloperSelectorTest())->run(),
    'AgentReviewerSelectorTest' => static fn(): int => (new AgentReviewerSelectorTest())->run(),
    'BacklogBoardServiceReviewingTest' => static fn(): int => (new BacklogBoardServiceReviewingTest())->run(),
    'BoardYamlStorageTest' => static fn(): int => (new BoardYamlStorageTest())->run(),
    'BodyFilePathResolverTest' => static fn(): int => (new BodyFilePathResolverTest())->run(),
    'AgentClientLauncherRegistryTest' => static fn(): int => (new AgentClientLauncherRegistryTest())->run(),
    'AgentCliOptionValidatorTest' => static fn(): int => (new AgentCliOptionValidatorTest())->run(),
    'AgentModelResolverTest' => static fn(): int => (new AgentModelResolverTest())->run(),
    'BacklogAgentRunnerStrictOptionsTest' => static fn(): int => (new BacklogAgentRunnerStrictOptionsTest())->run(),
    'BacklogAgentRunnerWiringTest' => static fn(): int => (new BacklogAgentRunnerWiringTest())->run(),
    'BacklogWorktreeServiceTest' => static fn(): int => (new BacklogWorktreeServiceTest())->run(),
    'BacklogCommitGateCommandTest' => static fn(): int => (new BacklogCommitGateCommandTest())->run(),
    'BacklogPreCommitHookTest' => static fn(): int => (new BacklogPreCommitHookTest())->run(),
    'BacklogReviewApproveCommandTest' => static fn(): int => (new BacklogReviewApproveCommandTest())->run(),
    'BacklogReviewNextCommandTest' => static fn(): int => (new BacklogReviewNextCommandTest())->run(),
    'PostMergeSessionStopperTest' => static fn(): int => (new PostMergeSessionStopperTest())->run(),
    'ReviewResumeNotifierTest' => static fn(): int => (new ReviewResumeNotifierTest())->run(),
    'GitClientTest' => static fn(): int => (new GitClientTest())->run(),
    'WorktreeScriptProxyTest' => static fn(): int => (new WorktreeScriptProxyTest())->run(),
    'ClaudeAgentLauncherTest' => static fn(): int => (new ClaudeAgentLauncherTest())->run(),
    'CodexAgentLauncherTest' => static fn(): int => (new CodexAgentLauncherTest())->run(),
    'DirectSessionDriverTest' => static fn(): int => (new DirectSessionDriverTest())->run(),
    'GeminiAgentLauncherTest' => static fn(): int => (new GeminiAgentLauncherTest())->run(),
    'LauncherFlagValidatorTest' => static fn(): int => (new LauncherFlagValidatorTest())->run(),
    'OpenCodeAgentLauncherTest' => static fn(): int => (new OpenCodeAgentLauncherTest())->run(),
    'SystemInteractiveProcessRunnerTest' => static fn(): int => (new SystemInteractiveProcessRunnerTest())->run(),
    'TmuxSessionDriverTest' => static fn(): int => (new TmuxSessionDriverTest())->run(),
    'AgentStopCommandTest' => static fn(): int => (new AgentStopCommandTest())->run(),

    'AgentLaunchPromptResolverTest' => static fn(): int => (new AgentLaunchPromptResolverTest())->run(),
    'AgentStartCommandTest' => static fn(): int => (new AgentStartCommandTest())->run(),
    'AgentWatchModeTest' => static fn(): int => (new AgentWatchModeTest())->run(),
    'EntryRebaseServiceTest' => static fn(): int => (new EntryRebaseServiceTest())->run(),
    'EntryRebaseCommandTest' => static fn(): int => (new EntryRebaseCommandTest())->run(),
    'AgentListCommandTest' => static fn(): int => (new AgentListCommandTest())->run(),
    'AgentStatusCommandTest' => static fn(): int => (new AgentStatusCommandTest())->run(),
    'AgentWhoamiCommandTest' => static fn(): int => (new AgentWhoamiCommandTest())->run(),
    'AgentSessionsCommandTest' => static fn(): int => (new AgentSessionsCommandTest())->run(),
    'BacklogAgentPruneCommandTest' => static fn(): int => (new BacklogAgentPruneCommandTest())->run(),
];

if ($suite !== null && !isset($suites[$suite])) {
    $console->fail(sprintf("Unknown test suite '%s'. Available: %s", $suite, implode(', ', array_keys($suites))));
}

$toRun = $suite !== null ? [$suite => $suites[$suite]] : $suites;

$totalFailed = 0;

foreach ($toRun as $name => $runner) {
    $console->line("▶ {$name}...");
    $failed = $runner();
    $totalFailed += $failed;
    if ($failed === 0) {
        $console->ok("All tests passed.");
    } else {
        $console->line("  ❌ {$failed} test(s) failed.");
    }
    $console->line('');
}

if ($totalFailed > 0) {
    $console->fail("{$totalFailed} test(s) failed.");
}

$console->ok('All backlog-agent tests passed.');
exit(0);
