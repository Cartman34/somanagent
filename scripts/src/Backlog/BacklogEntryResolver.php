<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

use SoManAgent\Script\TextSlugger;

/**
 * Resolves backlog feature and task entries from board state and CLI references.
 */
final class BacklogEntryResolver
{
    private TextSlugger $featureSlugger;

    public function __construct(TextSlugger $featureSlugger)
    {
        $this->featureSlugger = $featureSlugger;
    }

    public function assertNoActiveTasksForFeature(BacklogBoard $board, string $feature, string $command): void
    {
        $tasks = $this->findTaskEntriesByFeature($board, $feature);
        if ($tasks === []) {
            return;
        }

        throw new \RuntimeException(sprintf(
            '%s cannot continue while feature %s still has active task branches.',
            $command,
            $feature,
        ));
    }

    /**
     * @param array<string> $commandArgs
     */
    public function requireTaskByReferenceArgument(BacklogBoard $board, array $commandArgs, string $command): BoardEntryMatch
    {
        if (!isset($commandArgs[0]) || trim($commandArgs[0]) === '') {
            throw new \RuntimeException(sprintf('%s requires <feature/task>.', $command));
        }

        return $this->requireTaskByReference($board, $commandArgs[0], $command);
    }

    public function requireTaskByReference(BacklogBoard $board, string $reference, string $command): BoardEntryMatch
    {
        $normalizedReference = trim($reference);
        if ($normalizedReference === '') {
            throw new \RuntimeException(sprintf('%s requires a task reference.', $command));
        }

        if (str_contains($normalizedReference, '/')) {
            [$feature, $task] = array_pad(explode('/', $normalizedReference, 2), 2, '');
            $feature = $this->normalizeFeatureSlug($feature);
            $task = $this->normalizeFeatureSlug($task);

            foreach ($this->findTaskEntriesByFeature($board, $feature) as $match) {
                if ($match->getEntry()->getTask() === $task) {
                    return $match;
                }
            }

            throw new \RuntimeException(sprintf('Task not found: %s/%s', $feature, $task));
        }

        $task = $this->normalizeFeatureSlug($normalizedReference);
        $matches = $this->findTaskEntriesByTaskSlug($board, $task);
        if ($matches === []) {
            throw new \RuntimeException(sprintf('Task not found: %s', $task));
        }
        if (count($matches) > 1) {
            throw new \RuntimeException(sprintf(
                '%s requires <feature/task> because task slug %s is not unique.',
                $command,
                $task,
            ));
        }

        return $matches[0];
    }

    /**
     * @param array<string> $commandArgs
     */
    public function requireFeatureByReferenceArgument(BacklogBoard $board, array $commandArgs, string $command): string
    {
        if (!isset($commandArgs[0]) || trim($commandArgs[0]) === '') {
            throw new \RuntimeException(sprintf('%s requires <feature>.', $command));
        }

        return $this->requireFeatureByReference($board, $commandArgs[0], $command);
    }

    public function requireFeatureByReference(BacklogBoard $board, string $reference, string $command): string
    {
        $normalizedReference = $this->normalizeFeatureSlug($reference);
        if ($normalizedReference === '') {
            throw new \RuntimeException(sprintf('%s requires a feature reference.', $command));
        }

        $match = $this->findParentFeatureEntry($board, $normalizedReference);
        if ($match === null) {
            throw new \RuntimeException(sprintf('Feature not found: %s', $normalizedReference));
        }

        return $normalizedReference;
    }

    public function requireFeature(BacklogBoard $board, string $feature): BoardEntryMatch
    {
        $match = $this->findParentFeatureEntry($board, $feature);
        if ($match === null) {
            throw new \RuntimeException("Feature not found: {$feature}");
        }

        return $match;
    }

    public function requireParentFeature(BacklogBoard $board, string $feature): BoardEntryMatch
    {
        return $this->requireFeature($board, $feature);
    }

    public function getSingleFeatureForAgent(BacklogBoard $board, string $agent, bool $required): ?BoardEntry
    {
        $matches = $this->findFeatureEntriesByAgent($board, $agent);
        if ($matches === []) {
            if ($required) {
                throw new \RuntimeException("Agent {$agent} has no active feature.");
            }

            return null;
        }

        if (count($matches) > 1) {
            throw new \RuntimeException("Agent {$agent} has multiple active features. Resolve the backlog before continuing.");
        }

        return $matches[0]->getEntry();
    }

    public function requireSingleFeatureForAgent(BacklogBoard $board, string $agent): BoardEntryMatch
    {
        $matches = $this->findFeatureEntriesByAgent($board, $agent);
        if ($matches === []) {
            throw new \RuntimeException("Agent {$agent} has no active feature.");
        }

        if (count($matches) > 1) {
            throw new \RuntimeException("Agent {$agent} has multiple active features.");
        }

        return $matches[0];
    }

    public function getSingleTaskForAgent(BacklogBoard $board, string $agent, bool $required): ?BoardEntry
    {
        $matches = $this->findTaskEntriesByAgent($board, $agent);
        if ($matches === []) {
            if ($required) {
                throw new \RuntimeException("Agent {$agent} has no active task.");
            }

            return null;
        }

        if (count($matches) > 1) {
            throw new \RuntimeException("Agent {$agent} has multiple active tasks.");
        }

        return $matches[0]->getEntry();
    }

    public function requireSingleTaskForAgent(BacklogBoard $board, string $agent): BoardEntryMatch
    {
        $matches = $this->findTaskEntriesByAgent($board, $agent);
        if ($matches === []) {
            throw new \RuntimeException("Agent {$agent} has no active task.");
        }

        if (count($matches) > 1) {
            throw new \RuntimeException("Agent {$agent} has multiple active tasks.");
        }

        return $matches[0];
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function findFeaturesByAgent(BacklogBoard $board, string $agent): array
    {
        return $this->findFeatureEntriesByAgent($board, $agent);
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function findFeatureEntriesByAgent(BacklogBoard $board, string $agent): array
    {
        $matches = [];

        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->isFeatureEntry($entry) || $entry->getAgent() !== $agent) {
                continue;
            }

            $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        return $matches;
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function findTaskEntriesByAgent(BacklogBoard $board, string $agent): array
    {
        $matches = [];

        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->isTaskEntry($entry) || $entry->getAgent() !== $agent) {
                continue;
            }

            $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        return $matches;
    }

    public function findParentFeatureEntry(BacklogBoard $board, string $feature): ?BoardEntryMatch
    {
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->isFeatureEntry($entry)) {
                continue;
            }
            if ($entry->getFeature() !== $feature) {
                continue;
            }

            return new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        return null;
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function findTaskEntriesByFeature(BacklogBoard $board, string $feature): array
    {
        $matches = [];

        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->isTaskEntry($entry)) {
                continue;
            }
            if ($entry->getFeature() !== $feature) {
                continue;
            }

            $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        return $matches;
    }

    public function findTaskEntryByTaskSlug(BacklogBoard $board, string $task): ?BoardEntryMatch
    {
        return $this->findTaskEntriesByTaskSlug($board, $task)[0] ?? null;
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function findTaskEntriesByTaskSlug(BacklogBoard $board, string $task): array
    {
        $matches = [];

        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->isTaskEntry($entry)) {
                continue;
            }
            if ($entry->getTask() !== $task) {
                continue;
            }

            $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        return $matches;
    }

    private function normalizeFeatureSlug(string $text): string
    {
        return $this->featureSlugger->slugify($text);
    }

    private function entryKind(BoardEntry $entry): string
    {
        $kind = $entry->getKind();
        if ($kind !== null) {
            return $kind;
        }

        return $entry->getTask() !== null ? 'task' : 'feature';
    }

    private function isFeatureEntry(BoardEntry $entry): bool
    {
        return $this->entryKind($entry) === 'feature';
    }

    private function isTaskEntry(BoardEntry $entry): bool
    {
        return $this->entryKind($entry) === 'task';
    }
}
