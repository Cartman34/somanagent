<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog;

use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\Backlog\BacklogBoard;

final class BacklogScriptTestDriver
{
    private BacklogScriptTestContext $context;
    private ConsoleClient $consoleClient;
    private Console $console;

    public function __construct(BacklogScriptTestContext $context, ConsoleClient $consoleClient, Console $console)
    {
        $this->context = $context;
        $this->consoleClient = $consoleClient;
        $this->console = $console;
    }

    public function initializeArtifacts(): void
    {
        $this->resetArtifacts();
    }

    public function resetArtifacts(): void
    {
        $this->writeFile($this->context->boardPath, <<<MD
# Tableau du backlog

## Règles d'usage

- Fichier de test temporaire pour scripts/test-backlog-workflow.php.
- Ne pas utiliser ce fichier comme backlog de production.

## À faire

## Traitement en cours

## Suggestions

MD);

        $this->writeFile($this->context->reviewPath, <<<MD
# Revue en cours

## Règles d'usage

- Fichier de test temporaire pour scripts/test-backlog-workflow.php.
- Ne pas utiliser ce fichier comme review de production.

## Revue en cours

Aucune review en cours.

MD);
    }

    public function finalizeArtifacts(): void
    {
        if ($this->context->keepArtifacts) {
            $this->console->line(sprintf(
                'Kept test artifacts: %s and %s',
                $this->relativePath($this->context->boardPath),
                $this->relativePath($this->context->reviewPath),
            ));

            return;
        }

        if (is_file($this->context->boardPath)) {
            unlink($this->context->boardPath);
        }
        if (is_file($this->context->reviewPath)) {
            unlink($this->context->reviewPath);
        }
    }

    public function runHelpChecks(): void
    {
        $this->assertOutputContains($this->runBacklog([]), 'Commands:');
        $this->assertOutputContains($this->runBacklog(['help']), 'Commands:');
        $this->assertOutputContains($this->runBacklog(['help', 'feature-start']), 'feature-start');
        $this->assertOutputContains($this->runBacklog(['feature-start', '--help']), 'feature-start');
    }

    public function createTodoTask(string $text): void
    {
        $this->runBacklog(['task-create', $text]);
    }

    public function removeFirstTodoTask(): void
    {
        $this->runBacklog(['task-remove', '1']);
    }

    public function assertTodoContains(string $needle): void
    {
        $this->assertOutputContains($this->runBacklog(['task-todo-list']), $needle);
    }

    public function startNextFeature(string $agent): void
    {
        $this->runBacklog(['feature-start', '--agent', $agent]);
    }

    public function assignFeatureAsManager(string $feature, string $agent): void
    {
        $this->runBacklog(['feature-assign', $feature, '--agent', $agent], ['SOMANAGER_ROLE' => 'manager']);
    }

    public function unassignFeatureAsManager(string $feature, string $agent): void
    {
        $this->runBacklog(['feature-unassign', $feature, '--agent', $agent], ['SOMANAGER_ROLE' => 'manager']);
    }

    public function releaseFeature(string $agent, string $feature): void
    {
        $this->runBacklog(['feature-release', $feature, '--agent', $agent]);
    }

    public function closeFeature(string $feature): void
    {
        $this->runBacklog(['feature-close', $feature]);
    }

    public function featureStatus(string $featureOrAgent, bool $isAgent = false): string
    {
        return $isAgent
            ? $this->runBacklog(['feature-status', '--agent', $featureOrAgent])
            : $this->runBacklog(['feature-status', $featureOrAgent]);
    }

    public function addQueuedTaskToCurrentFeature(string $agent, string $featureText): void
    {
        $this->runBacklog(['feature-task-add', '--agent', $agent, '--feature-text', $featureText]);
    }

    public function requestTaskReview(string $agent, string $reference): void
    {
        $this->runBacklog(['task-review-request', '--agent', $agent, $reference]);
    }

    public function checkTaskReview(string $reference): void
    {
        $this->runBacklog(['task-review-check', $reference]);
    }

    public function rejectTaskReview(string $reference, string $bodyFile): void
    {
        $this->runBacklog(['task-review-reject', $reference, '--body-file', $bodyFile]);
    }

    public function reworkTask(string $agent, string $reference): void
    {
        $this->runBacklog(['task-rework', '--agent', $agent, $reference]);
    }

    public function approveTask(string $reference): void
    {
        $this->runBacklog(['task-review-approve', $reference]);
    }

    public function mergeTask(string $reference): void
    {
        $this->runBacklog(['feature-task-merge', $reference]);
    }

    public function requestFeatureReview(string $agent, string $feature): void
    {
        $this->runBacklog(['feature-review-request', '--agent', $agent, $feature]);
    }

    public function checkFeatureReview(string $feature): void
    {
        $this->runBacklog(['feature-review-check', $feature]);
    }

    public function rejectFeatureReview(string $feature, string $bodyFile): void
    {
        $this->runBacklog(['feature-review-reject', $feature, '--body-file', $bodyFile]);
    }

    public function reworkFeature(string $agent, string $feature): void
    {
        $this->runBacklog(['feature-rework', '--agent', $agent, $feature]);
    }

    public function approveFeature(string $feature, string $bodyFile): void
    {
        $this->runBacklog(['feature-review-approve', $feature, '--body-file', $bodyFile]);
    }

    public function blockFeature(string $agent, string $feature): void
    {
        $this->runBacklog(['feature-block', '--agent', $agent, $feature]);
    }

    public function unblockFeature(string $agent, string $feature): void
    {
        $this->runBacklog(['feature-unblock', '--agent', $agent, $feature]);
    }

    public function mergeFeature(string $feature, string $bodyFile): void
    {
        $this->runBacklog(['feature-merge', $feature, '--body-file', $bodyFile]);
    }

    public function assertActiveFeatureExists(string $feature): void
    {
        $board = $this->board();
        if ($board->findFeature($feature) === null) {
            throw new \RuntimeException("Expected active feature not found in test backlog: {$feature}");
        }
    }

    public function assertActiveFeatureMissing(string $feature): void
    {
        $board = $this->board();
        if ($board->findFeature($feature) !== null) {
            throw new \RuntimeException("Unexpected active feature still present in test backlog: {$feature}");
        }
    }

    public function assertReviewContains(string $needle): void
    {
        $contents = (string) file_get_contents($this->context->reviewPath);
        if (!str_contains($contents, $needle)) {
            throw new \RuntimeException("Expected review content not found: {$needle}");
        }
    }

    public function assertReviewMissing(string $needle): void
    {
        $contents = (string) file_get_contents($this->context->reviewPath);
        if (str_contains($contents, $needle)) {
            throw new \RuntimeException("Unexpected review content still present: {$needle}");
        }
    }

    public function assertStatusContains(string $featureOrAgent, string $needle, bool $isAgent = false): void
    {
        $this->assertOutputContains($this->featureStatus($featureOrAgent, $isAgent), $needle);
    }

    public function assertWorktreeListContains(string $needle): void
    {
        $this->assertOutputContains($this->runBacklog(['worktree-list']), $needle);
    }

    /**
     * @param array<string> $lines
     */
    public function createBodyFile(string $name, array $lines): string
    {
        $path = $this->context->tmpDir . '/' . $name;
        $this->writeFile($path, implode("\n", $lines) . "\n");

        return $path;
    }

    /**
     * @param array<string> $arguments
     * @param array<string, string> $env
     */
    public function runBacklog(array $arguments, array $env = []): string
    {
        $parts = [];
        foreach ($env as $key => $value) {
            $parts[] = sprintf('%s=%s', $key, escapeshellarg($value));
        }

        $parts[] = 'php scripts/backlog.php';
        foreach ($arguments as $argument) {
            $parts[] = escapeshellarg($argument);
        }

        $parts[] = '--test-mode';
        $parts[] = '--board-file';
        $parts[] = escapeshellarg($this->relativePath($this->context->boardPath));
        $parts[] = '--review-file';
        $parts[] = escapeshellarg($this->relativePath($this->context->reviewPath));

        if ($this->context->dryRun) {
            $parts[] = '--dry-run';
        }
        if ($this->context->verbose) {
            $parts[] = '--verbose';
        }

        $command = implode(' ', $parts);
        [$code, $output] = $this->consoleClient->captureWithExitCode($command);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Backlog command failed with exit code %d: %s\n%s",
                $code,
                $command,
                $output,
            ));
        }

        return $output;
    }

    private function board(): BacklogBoard
    {
        return new BacklogBoard($this->context->boardPath);
    }

    private function assertOutputContains(string $output, string $needle): void
    {
        if (!str_contains($output, $needle)) {
            throw new \RuntimeException("Expected command output to contain: {$needle}");
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("Unable to write test artifact: {$path}");
        }
    }

    private function relativePath(string $path): string
    {
        return $this->consoleClient->toRelativeProjectPath($path);
    }
}
