<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Runner;

use Sowapps\SoManAgent\Script\Backlog\Agent\Service\LauncherFlagReport;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\ShellProcessRunner;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\ClaudeAgentLauncher;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\CodexAgentLauncher;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\OpenCodeAgentLauncher;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\GeminiAgentLauncher;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\LauncherFlagValidator;

/**
 * Cross-checks the CLI flags declared by each AgentClientLauncher against the local binary `--help`.
 *
 * Skips a launcher when its binary is not installed. Exits 1 only when at least
 * one declared flag is missing from an available binary — the indication that
 * the upstream CLI has evolved and the launcher must be updated.
 */
final class ValidateAgentLaunchersRunner extends AbstractScriptRunner
{
    private const NAME = 'validate-agent-launchers';

    protected function getName(): string
    {
        return self::NAME;
    }

    protected function getDescription(): string
    {
        return 'Validate that every flag required by an AgentClientLauncher is still advertised by the local binary `--help` output';
    }

    protected function getOptions(): array
    {
        return [];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/validate-agent-launchers.php',
        ];
    }

    /**
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        $processRunner = new ShellProcessRunner();
        $launchers = [
            new ClaudeAgentLauncher($processRunner),
            new CodexAgentLauncher($processRunner),
            new OpenCodeAgentLauncher($processRunner),
            new GeminiAgentLauncher($processRunner),
        ];

        $reports = (new LauncherFlagValidator($processRunner))->validate($launchers);

        return $this->printReports($reports);
    }

    /**
     * @param list<LauncherFlagReport> $reports
     */
    private function printReports(array $reports): int
    {
        echo "Validating agent launcher CLI flags...\n";

        $checkedCount = 0;
        $skippedCount = 0;
        $failingReports = [];

        foreach ($reports as $report) {
            if ($report->skipped) {
                $skippedCount++;
                echo sprintf("  %s: SKIP (binary not installed)\n", $report->client);
                continue;
            }

            $checkedCount++;
            echo sprintf("  %s: %d flag(s)\n", $report->client, count($report->checks));
            foreach ($report->checks as $check) {
                echo sprintf("    [%s] %s\n", $check->present ? 'OK' : 'MISSING', $check->flag);
            }

            if ($report->hasMissingFlag()) {
                $failingReports[] = $report;
            }
        }

        echo "\n";

        if ($failingReports === []) {
            echo sprintf(
                "✓ All required flags present (%d checked, %d skipped).\n",
                $checkedCount,
                $skippedCount,
            );

            return 0;
        }

        echo "✗ Missing required CLI flags. The binary has likely evolved upstream:\n";
        foreach ($failingReports as $report) {
            foreach ($report->missingFlags() as $flag) {
                echo sprintf("  - %s: %s not found in `%s --help` output\n", $report->client, $flag, $report->client);
            }
        }
        echo "Update the corresponding AgentClientLauncher::requiredCliFlags() and buildLaunchCommand(), or pin a compatible binary version.\n";

        return 1;
    }
}
