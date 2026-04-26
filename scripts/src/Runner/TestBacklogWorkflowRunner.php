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
use SoManAgent\Script\Test\Backlog\Campaign\ScopedTaskLifecycleCampaign;
use SoManAgent\Script\Test\Backlog\Campaign\TodoAndPlainFeatureLifecycleCampaign;

final class TestBacklogWorkflowRunner extends AbstractScriptRunner
{
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
                ['name' => '--campaign', 'description' => 'Campaign to run: help, todo-and-plain-feature-lifecycle, scoped-task-lifecycle, feature-review-lifecycle, or all'],
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
        [$commandArgs, $options] = $this->parseArgs($args);
        $this->configureExecutionModes($options);

        if ($commandArgs !== []) {
            throw new \RuntimeException('This script only accepts named options. Use --campaign=<name>.');
        }

        $requestedCampaign = trim((string) ($options['campaign'] ?? 'all'));
        $allowRemote = isset($options['allow-remote']);
        $keepArtifacts = isset($options['keep-artifacts']);
        $runToken = sprintf('%s-%04d', date('YmdHis'), random_int(1000, 9999));

        $context = new BacklogScriptTestContext(
            projectRoot: $this->projectRoot,
            boardPath: $this->projectRoot . '/local/tmp/test-backlog-workflow-board.md',
            reviewPath: $this->projectRoot . '/local/tmp/test-backlog-workflow-review.md',
            tmpDir: $this->projectRoot . '/local/tmp',
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
        }

        $this->console->ok('Backlog workflow test campaign(s) completed.');

        return 0;
    }

    /**
     * @param array<string> $args
     * @return array{0: array<string>, 1: array<string, string|bool>}
     */
    private function parseArgs(array $args): array
    {
        $commandArgs = [];
        $options = [];

        while ($args !== []) {
            $arg = array_shift($args);
            if ($arg === null) {
                continue;
            }

            if (str_starts_with($arg, '--')) {
                $option = substr($arg, 2);
                if (str_contains($option, '=')) {
                    [$key, $value] = explode('=', $option, 2);
                    $options[$key] = $value;
                    continue;
                }

                $next = $args[0] ?? null;
                if ($next !== null && !str_starts_with($next, '--')) {
                    $options[$option] = array_shift($args);
                } else {
                    $options[$option] = true;
                }
                continue;
            }

            $commandArgs[] = $arg;
        }

        return [$commandArgs, $options];
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
                $campaigns['todo-and-plain-feature-lifecycle'],
                $campaigns['scoped-task-lifecycle'],
            ];

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
                'todo-and-plain-feature-lifecycle' => new TodoAndPlainFeatureLifecycleCampaign(),
                'scoped-task-lifecycle' => new ScopedTaskLifecycleCampaign(),
                'feature-review-lifecycle' => new FeatureReviewLifecycleCampaign(),
            ];
        }

        return $this->campaigns;
    }

    private function logVerbose(string $message): void
    {
        if ($this->verbose) {
            $this->console->info($message);
        }
    }
}
