<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Service;

use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Model\BacklogBoard;

/**
 * Generates <WA>/local/agent-context.md for every agent session start and resume.
 *
 * The file is hidden from git status of the WA via .git/info/exclude
 * (managed by BacklogWorktreeService::ensureWorktreeRuntimeIgnores).
 *
 * Section order (spec §6):
 *   1. Title
 *   2. Working directory
 *   3. Current task
 *   4. Allowed commands
 *   5. User keywords
 *   6. Backlog vocabulary
 *   7. Identification
 */
final class AgentContextBuilder
{
    private string $projectRoot;
    private BacklogBoardService $boardService;
    private string $boardPath;

    /**
     * @param string $projectRoot Absolute path to the main workspace
     * @param string $boardPath Absolute path to the backlog board file
     * @param BacklogBoardService $boardService
     */
    public function __construct(string $projectRoot, string $boardPath, BacklogBoardService $boardService)
    {
        $this->projectRoot = $projectRoot;
        $this->boardPath = $boardPath;
        $this->boardService = $boardService;
    }

    /**
     * Generates and writes the context file; returns its absolute path.
     */
    public function build(string $worktree, string $code, AgentRole $role): string
    {
        $contextFilePath = $worktree . '/local/agent-context.md';

        $dir = dirname($contextFilePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = $this->render($worktree, $code, $role);
        file_put_contents($contextFilePath, $content);

        $this->ensureContextExcluded($worktree);

        return $contextFilePath;
    }

    private function render(string $worktree, string $code, AgentRole $role): string
    {
        $date = (new \DateTimeImmutable())->format('Y-m-d');
        $sections = [];

        // 1. Title
        $sections[] = sprintf('# Agent %s — %s — %s', $code, $role->value, $date);

        // 2. Working directory
        $workingDirectoryRule = match ($role) {
            AgentRole::REVIEWER => 'Rule: stay in the shared developer WA and treat the workspace as review-only unless the reviewer workflow explicitly allows a command.',
            AgentRole::MANAGER => 'Rule: WP is the normal working directory. The manager may inspect or switch to a WA when the documented manager workflow allows it.',
            default => 'Rule: never read or write outside this directory. Do not access `$SOMANAGER_WP`. Do not run scripts outside the role\'s allowed list.',
        };
        $sections[] = implode("\n", [
            '## Working directory',
            '',
            $worktree,
            '',
            $workingDirectoryRule,
        ]);

        // 3. Current task
        $sections[] = $this->renderCurrentTask($code, $role);

        // 4. Allowed commands + 5. User keywords (from agent-<role>.md)
        $roleDocPath = $this->projectRoot . '/doc/development/agent-' . $role->value . '.md';
        if (is_file($roleDocPath)) {
            $roleDoc = (string) file_get_contents($roleDocPath);

            $allowedParts = [];
            $allowed = $this->extractSection($roleDoc, 'Allowed Commands');
            if ($allowed !== null) {
                $allowedParts[] = trim($allowed);
            }
            $doNot = $this->extractSection($roleDoc, 'Do Not');
            if ($doNot !== null) {
                $allowedParts[] = trim($doNot);
            }
            if ($allowedParts !== []) {
                $sections[] = "## Allowed commands\n\n" . implode("\n\n", $allowedParts);
            }

            $keywords = $this->extractSection($roleDoc, 'User Keywords');
            if ($keywords !== null) {
                $sections[] = "## User keywords\n\n" . trim($keywords);
            }
        }

        // 6. Backlog vocabulary
        $glossaryPath = $this->projectRoot . '/doc/development/backlog-glossary.md';
        if (is_file($glossaryPath)) {
            $glossary = (string) file_get_contents($glossaryPath);
            $sections[] = "## Backlog vocabulary\n\n" . trim($glossary);
        }

        // 7. Identification
        $sections[] = implode("\n", [
            '## Identification',
            '',
            'To confirm your session context:',
            '```',
            'php scripts/backlog-agent.php whoami',
            'echo $SOMANAGER_AGENT',
            '```',
        ]);

        return implode("\n\n---\n\n", $sections) . "\n";
    }

    private function renderCurrentTask(string $code, AgentRole $role): string
    {
        $header = '## Current task';

        if ($role === AgentRole::MANAGER) {
            return $this->renderManagerCurrentTask($code, $header);
        }

        if ($role === AgentRole::REVIEWER) {
            return $this->renderReviewerCurrentTask($code, $header);
        }

        if (!is_file($this->boardPath)) {
            return $header . "\n\nNo active task. Wait for explicit instruction (typically the keyword `next`).";
        }

        try {
            $board = $this->boardService->loadBoard($this->boardPath);
            $entries = $this->boardService->findActiveEntriesByAgent($board, $code);
        } catch (\RuntimeException) {
            return $header . "\n\nNo active task. Wait for explicit instruction (typically the keyword `next`).";
        }

        if ($entries === []) {
            return $header . "\n\nNo active task. Wait for explicit instruction (typically the keyword `next`).";
        }

        $entry = $entries[0]->getEntry();
        $feature = $entry->getFeature() ?? '';
        $task = $entry->getTask() ?? '';
        $branch = $entry->getBranch() ?? '';
        $base = $entry->getBase() ?? '';
        $stage = $entry->getStage() ?? '';

        $lines = [$header, ''];

        if ($task !== '') {
            $lines[] = sprintf('Feature: %s', $feature);
            $lines[] = sprintf('Task: %s', $task);
            $lines[] = sprintf('Ref: %s/%s', $feature, $task);
        } else {
            $lines[] = sprintf('Feature: %s', $feature);
        }

        $lines[] = sprintf('Branch: %s', $branch);
        $lines[] = sprintf('Base: %s', $base);
        $lines[] = sprintf('Stage: %s', $stage);

        $extraLines = $entry->getExtraLines();
        if ($extraLines !== []) {
            $lines[] = '';
            $lines[] = 'Sub-tasks:';
            foreach ($extraLines as $line) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    private function renderManagerCurrentTask(string $code, string $header): string
    {
        $lines = [$header, '', 'Manager session in WP.'];

        if (!is_file($this->boardPath)) {
            return implode("\n", $lines);
        }

        try {
            $board = $this->boardService->loadBoard($this->boardPath);
            $entries = $this->boardService->findActiveEntriesByAgent($board, $code);
        } catch (\RuntimeException) {
            return implode("\n", $lines);
        }

        if ($entries === []) {
            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = 'Entries assigned to this manager code:';
        foreach ($entries as $match) {
            $entry = $match->getEntry();
            $feature = $entry->getFeature() ?? '';
            $task = $entry->getTask() ?? '';
            $ref = $task !== '' ? "{$feature}/{$task}" : $feature;
            $stage = $entry->getStage() ?? '';
            $lines[] = sprintf('  %s (%s)', $ref, $stage);
        }

        return implode("\n", $lines);
    }

    private function renderReviewerCurrentTask(string $reviewerCode, string $header): string
    {
        if (!is_file($this->boardPath)) {
            return $header . "\n\nNo review assigned.";
        }

        try {
            $board = $this->boardService->loadBoard($this->boardPath);
            $match = $this->boardService->findReviewingEntryByReviewer($board, $reviewerCode);
        } catch (\RuntimeException) {
            return $header . "\n\nNo review assigned.";
        }

        if ($match === null) {
            return $header . "\n\nNo review assigned.";
        }

        $entry = $match->getEntry();
        $feature = $entry->getFeature() ?? '';
        $task = $entry->getTask() ?? '';
        $devCode = $entry->getAgent() ?? '';
        $branch = $entry->getBranch() ?? '';
        $base = $entry->getBase() ?? '';
        $stage = $entry->getStage() ?? '';

        $lines = [$header, ''];

        if ($task !== '') {
            $lines[] = sprintf('Feature: %s', $feature);
            $lines[] = sprintf('Task: %s', $task);
            $lines[] = sprintf('Ref: %s/%s', $feature, $task);
        } else {
            $lines[] = sprintf('Feature: %s', $feature);
        }

        $lines[] = sprintf('Developer: %s', $devCode);
        $lines[] = sprintf('Branch: %s', $branch);
        $lines[] = sprintf('Base: %s', $base);
        $lines[] = sprintf('Stage: %s', $stage);
        $lines[] = sprintf('Reviewer: %s', $reviewerCode);

        return implode("\n", $lines);
    }

    private function extractSection(string $content, string $heading): ?string
    {
        $pattern = '/^## ' . preg_quote($heading, '/') . '\s*\n(.*?)(?=^## |\z)/ms';
        if (preg_match($pattern, $content, $m)) {
            return $m[1];
        }

        return null;
    }

    private function ensureContextExcluded(string $worktree): void
    {
        $excludeFile = $worktree . '/.git/info/exclude';
        $excludeDir = dirname($excludeFile);

        if (!is_dir($excludeDir)) {
            return;
        }

        $pattern = 'local/agent-context.md';
        $existing = is_file($excludeFile) ? (string) file_get_contents($excludeFile) : '';

        if (!str_contains($existing, $pattern)) {
            file_put_contents($excludeFile, $existing . (str_ends_with($existing, "\n") || $existing === '' ? '' : "\n") . $pattern . "\n");
        }
    }
}
