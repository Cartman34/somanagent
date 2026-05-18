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
use SoManAgent\Script\Backlog\Agent\Test\AgentResumeCommandTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentDeveloperSelectorTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentReviewerSelectorTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentSessionServiceTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentSessionsCommandTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentLaunchPromptResolverTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentStartCommandManagerTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentStartCommandTest;
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
use SoManAgent\Script\Backlog\Test\BacklogReviewNextCommandTest;
use SoManAgent\Script\Backlog\Test\BacklogWorktreeServiceTest;
use SoManAgent\Script\Console;

$console = Console::getInstance();

// Parse --suite option
$suite = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--suite=')) {
        $suite = substr($arg, 8);
    }
}

/** @var array<string, class-string> */
$suites = [
    'AgentCodeServiceTest' => AgentCodeServiceTest::class,
    'AgentSessionServiceTest' => AgentSessionServiceTest::class,
    'AgentStartCommandManagerTest' => AgentStartCommandManagerTest::class,
    'AgentContextBuilderTest' => AgentContextBuilderTest::class,
    'AgentDeveloperSelectorTest' => AgentDeveloperSelectorTest::class,
    'AgentReviewerSelectorTest' => AgentReviewerSelectorTest::class,
    'BacklogBoardServiceReviewingTest' => BacklogBoardServiceReviewingTest::class,
    'BoardYamlStorageTest' => BoardYamlStorageTest::class,
    'BodyFilePathResolverTest' => BodyFilePathResolverTest::class,
    'AgentClientLauncherRegistryTest' => AgentClientLauncherRegistryTest::class,
    'AgentCliOptionValidatorTest' => AgentCliOptionValidatorTest::class,
    'AgentModelResolverTest' => AgentModelResolverTest::class,
    'BacklogAgentRunnerStrictOptionsTest' => BacklogAgentRunnerStrictOptionsTest::class,
    'BacklogWorktreeServiceTest' => BacklogWorktreeServiceTest::class,
    'BacklogCommitGateCommandTest' => BacklogCommitGateCommandTest::class,
    'BacklogPreCommitHookTest' => BacklogPreCommitHookTest::class,
    'BacklogReviewNextCommandTest' => BacklogReviewNextCommandTest::class,
    'WorktreeScriptProxyTest' => WorktreeScriptProxyTest::class,
    'ClaudeAgentLauncherTest' => ClaudeAgentLauncherTest::class,
    'CodexAgentLauncherTest' => CodexAgentLauncherTest::class,
    'DirectSessionDriverTest' => DirectSessionDriverTest::class,
    'GeminiAgentLauncherTest' => GeminiAgentLauncherTest::class,
    'LauncherFlagValidatorTest' => LauncherFlagValidatorTest::class,
    'OpenCodeAgentLauncherTest' => OpenCodeAgentLauncherTest::class,
    'SystemInteractiveProcessRunnerTest' => SystemInteractiveProcessRunnerTest::class,
    'TmuxSessionDriverTest' => TmuxSessionDriverTest::class,
    'AgentStopCommandTest' => AgentStopCommandTest::class,
    'AgentResumeCommandTest' => AgentResumeCommandTest::class,
    'AgentLaunchPromptResolverTest' => AgentLaunchPromptResolverTest::class,
    'AgentStartCommandTest' => AgentStartCommandTest::class,
    'EntryRebaseServiceTest' => EntryRebaseServiceTest::class,
    'EntryRebaseCommandTest' => EntryRebaseCommandTest::class,
    'AgentListCommandTest' => AgentListCommandTest::class,
    'AgentStatusCommandTest' => AgentStatusCommandTest::class,
    'AgentWhoamiCommandTest' => AgentWhoamiCommandTest::class,
    'AgentSessionsCommandTest' => AgentSessionsCommandTest::class,
    'BacklogAgentPruneCommandTest' => BacklogAgentPruneCommandTest::class,
];

if ($suite !== null && !isset($suites[$suite])) {
    $console->fail(sprintf("Unknown test suite '%s'. Available: %s", $suite, implode(', ', array_keys($suites))));
}

$toRun = $suite !== null ? [$suite => $suites[$suite]] : $suites;

$totalFailed = 0;

foreach ($toRun as $name => $class) {
    $console->line("▶ {$name}...");
    $test = new $class();
    $failed = $test->run();
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
