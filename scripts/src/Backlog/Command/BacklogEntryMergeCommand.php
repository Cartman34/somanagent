<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogCommandFactory;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntryMatch;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;

/**
 * Command for resolving and merging a feature or a fully qualified task reference.
 */
final class BacklogEntryMergeCommand extends AbstractBacklogCommand
{
    /**
     * Command factory used to delegate to the existing merge implementations after resolution.
     */
    private BacklogCommandFactory $commandFactory;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogCommandFactory $commandFactory
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogCommandFactory $commandFactory
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->commandFactory = $commandFactory;
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     * @return void
     */
    public function handle(array $commandArgs, array $options): void
    {
        $reviewer = $this->resolveReviewer($options);
        $reference = $this->resolveReference($commandArgs);
        $board = $this->loadBoard();

        if (str_contains($reference, '/')) {
            $this->handleTaskMerge($board, $reference, $reviewer, $options);

            return;
        }

        $this->handleFeatureMerge($board, $reference, $reviewer, $options);
    }

    /**
     * @param array<string, bool|string> $options
     */
    private function resolveReviewer(array $options): string
    {
        $agentOption = $options['agent'] ?? null;
        $reviewer = is_string($agentOption) ? $this->boardService->sanitizeString($agentOption) : null;
        if ($reviewer === null) {
            throw new \RuntimeException('entry-merge requires --agent=<reviewer>.');
        }

        return $reviewer;
    }

    /**
     * @param list<string> $commandArgs
     */
    private function resolveReference(array $commandArgs): string
    {
        $reference = trim($commandArgs[0] ?? '');
        if ($reference === '') {
            throw new \RuntimeException('entry-merge requires <feature> or <feature/task>.');
        }

        return $reference;
    }

    /**
     * @param array<string, bool|string> $options
     */
    private function handleFeatureMerge(BacklogBoard $board, string $reference, string $reviewer, array $options): void
    {
        $feature = $this->boardService->normalizeFeatureSlug($reference);
        if ($this->boardService->findParentFeatureEntry($board, $feature) === null) {
            $taskMatches = $this->boardService->findTaskEntriesByTaskSlug($board, $feature);
            if ($taskMatches !== []) {
                throw new \RuntimeException(sprintf(
                    'entry-merge refuses short task reference `%s`; use `<feature/task>` instead.',
                    $feature,
                ));
            }

            throw new \RuntimeException(sprintf('Feature not found for entry-merge: %s', $feature));
        }

        $arguments = [$feature];
        $delegatedOptions = [];
        if (array_key_exists('body-file', $options)) {
            $delegatedOptions['body-file'] = $options['body-file'];
        }
        $equivalentCommand = BacklogCommandName::FEATURE_MERGE->value . ' ' . $feature;
        if (isset($delegatedOptions['body-file']) && is_string($delegatedOptions['body-file'])) {
            $equivalentCommand .= ' --body-file ' . $delegatedOptions['body-file'];
        }

        $this->displayResolvedMerge(
            $reviewer,
            'feature',
            $feature,
            'main',
            $equivalentCommand,
        );

        $this->commandFactory->createHandler(BacklogCommandName::FEATURE_MERGE->value)->handle($arguments, $delegatedOptions);
    }

    /**
     * @param array<string, bool|string> $options
     */
    private function handleTaskMerge(BacklogBoard $board, string $reference, string $reviewer, array $options): void
    {
        if (array_key_exists('body-file', $options)) {
            throw new \RuntimeException('entry-merge accepts --body-file only for feature merges.');
        }

        $match = $this->resolveFullTaskReference($board, $reference);
        $entry = $match->getEntry();
        $target = $this->boardService->getTaskReviewKey($entry);
        $parentFeature = $entry->getFeature() ?? '';

        $this->displayResolvedMerge(
            $reviewer,
            'task',
            $target,
            $parentFeature,
            BacklogCommandName::FEATURE_TASK_MERGE->value . ' ' . $target,
        );

        $this->commandFactory->createHandler(BacklogCommandName::FEATURE_TASK_MERGE->value)->handle([$target], []);
    }

    private function resolveFullTaskReference(BacklogBoard $board, string $reference): BoardEntryMatch
    {
        [$featurePart, $taskPart] = array_pad(explode('/', $reference, 2), 2, '');
        if (trim($featurePart) === '' || trim($taskPart) === '') {
            throw new \RuntimeException('entry-merge task references must use `<feature/task>` with both parts filled.');
        }

        $feature = $this->boardService->normalizeFeatureSlug($featurePart);
        $task = $this->boardService->normalizeFeatureSlug($taskPart);

        foreach ($this->boardService->findTaskEntriesByFeature($board, $feature) as $match) {
            if ($match->getEntry()->getTask() === $task) {
                return $match;
            }
        }

        if ($this->boardService->findParentFeatureEntry($board, $feature) === null) {
            throw new \RuntimeException(sprintf(
                'Task not found for entry-merge: %s/%s. The parent feature %s is not active.',
                $feature,
                $task,
                $feature,
            ));
        }

        throw new \RuntimeException(sprintf(
            'Task not found for entry-merge: %s/%s. Use an active `<feature/task>` reference.',
            $feature,
            $task,
        ));
    }

    private function displayResolvedMerge(
        string $reviewer,
        string $type,
        string $target,
        string $mergeTarget,
        string $equivalentCommand
    ): void {
        $this->presenter->displayLine('[Entry merge]');
        $this->presenter->displayLine('Reviewer: ' . $reviewer);
        $this->presenter->displayLine('Resolved type: ' . $type);
        $this->presenter->displayLine('Target: ' . $target);
        $this->presenter->displayLine('Merge target: ' . $mergeTarget);
        $this->presenter->displayLine('Equivalent command: ' . $equivalentCommand);
    }
}
