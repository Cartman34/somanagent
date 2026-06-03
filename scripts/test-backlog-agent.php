#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run unit tests for scripts/backlog-agent.php classes
// Usage: php scripts/test-backlog-agent.php
// Usage: php scripts/test-backlog-agent.php --suite=AgentCodeServiceTest

require_once __DIR__ . '/src/bootstrap.php';

use Sowapps\SoManAgent\Script\Backlog\Agent\Test\AgentClientLauncherRegistryTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\AgentCliOptionValidatorTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\AgentCodeServiceTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\AgentContextBuilderTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\AgentListCommandTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\AgentModelResolverTest;

use Sowapps\SoManAgent\Script\Backlog\Agent\Test\AgentDeveloperSelectorTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\AgentReviewerSelectorTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\AgentSessionServiceTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\AgentSessionsCommandTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\AgentLaunchPromptResolverTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\AgentStartCommandManagerTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\AgentStartCommandTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\AgentWatchModeTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\EntryRebaseCommandTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\EntryRebaseServiceTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\AgentStatusCommandTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\AgentStopCommandTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\AgentWhoamiCommandTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\BacklogAgentPruneCommandTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\BacklogBoardServiceReviewingTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\BoardYamlStorageTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\BodyFilePathResolverTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\BacklogAgentRunnerStrictOptionsTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\BacklogAgentRunnerWiringTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\ClaudeAgentLauncherTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\CodexAgentLauncherTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\DirectSessionDriverTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\GeminiAgentLauncherTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\LauncherFlagValidatorTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\OpenCodeAgentLauncherTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\SystemInteractiveProcessRunnerTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\TmuxSessionDriverTest;
use Sowapps\SoManAgent\Script\Backlog\Agent\Test\WorktreeScriptProxyTest;
use Sowapps\SoManAgent\Script\Backlog\Test\BacklogCommitGateCommandTest;
use Sowapps\SoManAgent\Script\Backlog\Test\BacklogPreCommitHookTest;
use Sowapps\SoManAgent\Script\Backlog\Test\BacklogReviewApproveCommandTest;
use Sowapps\SoManAgent\Script\Backlog\Test\BacklogReviewRejectCommandTest;
use Sowapps\SoManAgent\Script\Backlog\Test\BacklogReviewNextCommandTest;
use Sowapps\SoManAgent\Script\Backlog\Test\BacklogWorktreeServiceTest;
use Sowapps\SoManAgent\Script\Backlog\Test\PostMergeSessionStopperTest;
use Sowapps\SoManAgent\Script\Backlog\Test\ReviewResumeNotifierTest;
use Sowapps\SoManAgent\Script\Client\Test\GitClientTest;
use Sowapps\SoManAgent\Script\Console;

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
    'BacklogReviewRejectCommandTest' => static fn(): int => (new BacklogReviewRejectCommandTest())->run(),
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
