<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\LauncherFlagValidator;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\ProcessRunner;
/**
 * Unit tests for LauncherFlagValidator.
 *
 * The tests stub the ProcessRunner so the local environment never invokes a
 * real claude/codex/opencode/gemini binary; the help texts are static fixtures
 * shaped like real CLI help output.
 */
final class LauncherFlagValidatorTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testSkipsLauncherWhenBinaryNotInstalled();
        $failed += $this->testReportsOkWhenAllFlagsArePresent();
        $failed += $this->testReportsMissingWhenFlagAbsentFromHelp();
        $failed += $this->testWordBoundaryAvoidsSpuriousFlagMatches();
        $failed += $this->testCapturesStderrHelpThrough2to1Redirection();
        $failed += $this->testSimulatedRegressionForUnknownFakeFlag();

        return $failed;
    }

    private function testSkipsLauncherWhenBinaryNotInstalled(): int
    {
        $runner = new StubHelpRunner(installed: false, helpOutput: '');
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE, [], false, ['--append-system-prompt']);

        $reports = (new LauncherFlagValidator($runner))->validate([$launcher]);

        if (count($reports) !== 1 || !$reports[0]->skipped || $reports[0]->checks !== []) {
            echo "FAIL testSkipsLauncherWhenBinaryNotInstalled: expected skipped report with empty checks\n";
            return 1;
        }
        if ($runner->lastCommand !== null) {
            echo "FAIL testSkipsLauncherWhenBinaryNotInstalled: --help must not be invoked when binary is unavailable\n";
            return 1;
        }

        echo "OK testSkipsLauncherWhenBinaryNotInstalled\n";
        return 0;
    }

    private function testReportsOkWhenAllFlagsArePresent(): int
    {
        $help = <<<HELP
Usage: claude [options]

Options:
  --append-system-prompt <text>   Append text to the system prompt
  --resume <session>              Resume a specific session
  --continue                      Continue the most recent session
HELP;
        $runner = new StubHelpRunner(installed: true, helpOutput: $help);
        $launcher = new FakeAgentClientLauncher(
            AgentClient::CLAUDE,
            [],
            true,
            ['--append-system-prompt', '--resume', '--continue'],
        );

        $reports = (new LauncherFlagValidator($runner))->validate([$launcher]);

        if ($reports[0]->skipped || $reports[0]->hasMissingFlag()) {
            echo "FAIL testReportsOkWhenAllFlagsArePresent: expected all flags present\n";
            return 1;
        }
        if (count($reports[0]->checks) !== 3) {
            echo "FAIL testReportsOkWhenAllFlagsArePresent: expected 3 checks\n";
            return 1;
        }

        echo "OK testReportsOkWhenAllFlagsArePresent\n";
        return 0;
    }

    private function testReportsMissingWhenFlagAbsentFromHelp(): int
    {
        $help = <<<HELP
Usage: claude [options]

Options:
  --append-system-prompt <text>   Append text to the system prompt
  --continue                      Continue the most recent session
HELP;
        $runner = new StubHelpRunner(installed: true, helpOutput: $help);
        $launcher = new FakeAgentClientLauncher(
            AgentClient::CLAUDE,
            [],
            true,
            ['--append-system-prompt', '--cwd', '--continue'],
        );

        $reports = (new LauncherFlagValidator($runner))->validate([$launcher]);
        $missing = $reports[0]->missingFlags();

        if ($missing !== ['--cwd']) {
            echo "FAIL testReportsMissingWhenFlagAbsentFromHelp: expected only --cwd missing, got: " . implode(',', $missing) . "\n";
            return 1;
        }

        echo "OK testReportsMissingWhenFlagAbsentFromHelp\n";
        return 0;
    }

    private function testWordBoundaryAvoidsSpuriousFlagMatches(): int
    {
        // -C only appears as a substring of unrelated words, never as a delimited token
        $help = "Usage: foo --bar\nThe word UNKNOWN-CHAR contains -Characters and SUB-CASE strings.\n";
        $runner = new StubHelpRunner(installed: true, helpOutput: $help);
        $launcher = new FakeAgentClientLauncher(AgentClient::CODEX, [], true, ['-C']);

        $reports = (new LauncherFlagValidator($runner))->validate([$launcher]);

        if (!$reports[0]->hasMissingFlag()) {
            echo "FAIL testWordBoundaryAvoidsSpuriousFlagMatches: -C must not match as a substring of an unrelated word\n";
            return 1;
        }

        // Now -C present as a real token
        $help2 = "Options:\n  -C <dir>   Change to dir before running\n";
        $runner2 = new StubHelpRunner(installed: true, helpOutput: $help2);
        $reports2 = (new LauncherFlagValidator($runner2))->validate([$launcher]);

        if ($reports2[0]->hasMissingFlag()) {
            echo "FAIL testWordBoundaryAvoidsSpuriousFlagMatches: -C must match when delimited\n";
            return 1;
        }

        echo "OK testWordBoundaryAvoidsSpuriousFlagMatches\n";
        return 0;
    }

    private function testCapturesStderrHelpThrough2to1Redirection(): int
    {
        $help = "Options:\n  --resume <id>\n";
        $runner = new StubHelpRunner(installed: true, helpOutput: $help);
        $launcher = new FakeAgentClientLauncher(AgentClient::CLAUDE, [], true, ['--resume']);

        (new LauncherFlagValidator($runner))->validate([$launcher]);

        if ($runner->lastCommand === null || !str_contains($runner->lastCommand, '2>&1')) {
            echo "FAIL testCapturesStderrHelpThrough2to1Redirection: command must merge stderr into stdout via 2>&1\n";
            return 1;
        }

        echo "OK testCapturesStderrHelpThrough2to1Redirection\n";
        return 0;
    }

    private function testSimulatedRegressionForUnknownFakeFlag(): int
    {
        // A launcher declares a fictitious flag the binary will never advertise.
        $help = "Options:\n  --real-flag\n";
        $runner = new StubHelpRunner(installed: true, helpOutput: $help);
        $launcher = new FakeAgentClientLauncher(
            AgentClient::OPENCODE,
            [],
            true,
            ['--real-flag', '--nonexistent-flag'],
        );

        $reports = (new LauncherFlagValidator($runner))->validate([$launcher]);

        if ($reports[0]->missingFlags() !== ['--nonexistent-flag']) {
            echo "FAIL testSimulatedRegressionForUnknownFakeFlag: expected --nonexistent-flag missing\n";
            return 1;
        }

        echo "OK testSimulatedRegressionForUnknownFakeFlag\n";
        return 0;
    }
}

/**
 * ProcessRunner stub that records the last invoked command and returns a canned
 * help fixture without ever running a real binary.
 */
final class StubHelpRunner implements ProcessRunner
{
    public ?string $lastCommand = null;

    /**
     * @param bool $installed Whether the binary is considered installed by succeeds()
     * @param string|null $helpOutput Canned help output; null simulates a process_open failure
     */
    public function __construct(
        private bool $installed,
        private ?string $helpOutput,
    ) {}

    /**
     * Mimics `which <bin>` — used by launcher::isAvailable().
     */
    public function succeeds(string $command): bool
    {
        return $this->installed;
    }

    /**
     * Records the command and returns the canned help output (or null on simulated failure).
     */
    public function output(string $command, string $cwd = ''): ?string
    {
        $this->lastCommand = $command;

        return $this->helpOutput;
    }
}
