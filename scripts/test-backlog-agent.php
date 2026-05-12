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
use SoManAgent\Script\Backlog\Agent\Test\AgentResumeCommandTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentReviewerSelectorTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentSessionServiceTest;
use SoManAgent\Script\Backlog\Agent\Test\AgentStopCommandTest;
use SoManAgent\Script\Backlog\Agent\Test\BacklogAgentRunnerStrictOptionsTest;
use SoManAgent\Script\Backlog\Agent\Test\ClaudeAgentLauncherTest;
use SoManAgent\Script\Backlog\Agent\Test\CodexAgentLauncherTest;
use SoManAgent\Script\Backlog\Agent\Test\GeminiAgentLauncherTest;
use SoManAgent\Script\Backlog\Agent\Test\OpenCodeAgentLauncherTest;
use SoManAgent\Script\Backlog\Agent\Test\SystemInteractiveProcessRunnerTest;
use SoManAgent\Script\Backlog\Agent\Test\WorktreeScriptProxyTest;
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
    'AgentContextBuilderTest' => AgentContextBuilderTest::class,
    'AgentReviewerSelectorTest' => AgentReviewerSelectorTest::class,
    'AgentClientLauncherRegistryTest' => AgentClientLauncherRegistryTest::class,
    'AgentCliOptionValidatorTest' => AgentCliOptionValidatorTest::class,
    'BacklogAgentRunnerStrictOptionsTest' => BacklogAgentRunnerStrictOptionsTest::class,
    'WorktreeScriptProxyTest' => WorktreeScriptProxyTest::class,
    'ClaudeAgentLauncherTest' => ClaudeAgentLauncherTest::class,
    'CodexAgentLauncherTest' => CodexAgentLauncherTest::class,
    'GeminiAgentLauncherTest' => GeminiAgentLauncherTest::class,
    'OpenCodeAgentLauncherTest' => OpenCodeAgentLauncherTest::class,
    'SystemInteractiveProcessRunnerTest' => SystemInteractiveProcessRunnerTest::class,
    'AgentStopCommandTest' => AgentStopCommandTest::class,
    'AgentResumeCommandTest' => AgentResumeCommandTest::class,
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
