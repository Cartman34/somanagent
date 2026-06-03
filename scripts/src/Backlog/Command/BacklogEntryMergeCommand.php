<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Command;

use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPresenter;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntryMatch;
use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntry;
/**
 * Command for resolving and merging one backlog entry.
 */
final class BacklogEntryMergeCommand extends AbstractBacklogCommand
{
    private BacklogFeatureMergeCommand $featureMergeCommand;

    private BacklogFeatureTaskMergeCommand $featureTaskMergeCommand;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogFeatureMergeCommand $featureMergeCommand
     * @param BacklogFeatureTaskMergeCommand $featureTaskMergeCommand
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogFeatureMergeCommand $featureMergeCommand,
        BacklogFeatureTaskMergeCommand $featureTaskMergeCommand
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->featureMergeCommand = $featureMergeCommand;
        $this->featureTaskMergeCommand = $featureTaskMergeCommand;
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     * @return void
     */
    public function handle(array $commandArgs, array $options): void
    {
        $caller = $this->resolveCallerAgent();
        $callerRole = $this->readCallerRole() ?? '-';
        $reference = $this->resolveReference($commandArgs);
        $board = $this->loadBoard();

        if (str_contains($reference, '/')) {
            $this->handleTaskMerge($board, $reference, $caller, $callerRole, $options);

            return;
        }

        $this->handleFeatureMerge($board, $reference, $caller, $callerRole, $options);
    }

    private function resolveCallerAgent(): string
    {
        $caller = $this->boardService->sanitizeString($this->requireCallerAgent());
        if ($caller === null) {
            throw new \RuntimeException('Command requires SOMANAGER_AGENT=<code>.');
        }

        return $caller;
    }

    /**
     * @param list<string> $commandArgs
     */
    private function resolveReference(array $commandArgs): string
    {
        $reference = trim($commandArgs[0] ?? '');
        if ($reference === '') {
            throw new \RuntimeException('merge requires <entry-ref>.');
        }

        return $reference;
    }

    /**
     * @param array<string, bool|string> $options
     */
    private function handleFeatureMerge(BacklogBoard $board, string $reference, string $caller, string $callerRole, array $options): void
    {
        $feature = $this->boardService->normalizeFeatureSlug($reference);
        $featureMatch = $this->boardService->findParentFeatureEntry($board, $feature);
        if ($featureMatch === null) {
            $taskMatches = $this->boardService->findTaskEntriesByTaskSlug($board, $feature);
            if ($taskMatches !== []) {
                throw new \RuntimeException(sprintf(
                    'merge refuses short task reference `%s`; use `<entry-ref>` instead.',
                    $feature,
                ));
            }

            throw new \RuntimeException(sprintf('Feature not found for merge: %s', $feature));
        }

        $arguments = [$feature];
        $delegatedOptions = [];
        if (array_key_exists(BacklogCliOption::BODY_FILE->value, $options)) {
            $delegatedOptions[BacklogCliOption::BODY_FILE->value] = $options[BacklogCliOption::BODY_FILE->value];
        }
        $equivalentCommand = BacklogCommandName::FEATURE_MERGE->value . ' ' . $feature;
        if (isset($delegatedOptions[BacklogCliOption::BODY_FILE->value]) && is_string($delegatedOptions[BacklogCliOption::BODY_FILE->value])) {
            $equivalentCommand .= ' --body-file ' . $delegatedOptions[BacklogCliOption::BODY_FILE->value];
        }

        $this->displayResolvedMerge(
            $featureMatch->getEntry(),
            $caller,
            $callerRole,
            'feature',
            $feature,
            $feature,
            'main',
            $equivalentCommand,
        );

        $this->featureMergeCommand->performMerge($arguments, $delegatedOptions);
    }

    /**
     * @param array<string, bool|string> $options
     */
    private function handleTaskMerge(BacklogBoard $board, string $reference, string $caller, string $callerRole, array $options): void
    {
        if (array_key_exists(BacklogCliOption::BODY_FILE->value, $options)) {
            throw new \RuntimeException('merge accepts --body-file only for feature merges.');
        }

        $match = $this->resolveFullTaskReference($board, $reference);
        $entry = $match->getEntry();
        $target = $this->boardService->getTaskReviewKey($entry);
        $parentFeature = $entry->getFeature() ?? '';

        $this->displayResolvedMerge(
            $entry,
            $caller,
            $callerRole,
            'task',
            $target,
            $target,
            $parentFeature,
            BacklogCommandName::FEATURE_TASK_MERGE->value . ' ' . $target,
        );

        $this->featureTaskMergeCommand->performMerge([$target], []);
    }

    private function resolveFullTaskReference(BacklogBoard $board, string $reference): BoardEntryMatch
    {
        [$featurePart, $taskPart] = array_pad(explode('/', $reference, 2), 2, '');
        if (trim($featurePart) === '' || trim($taskPart) === '') {
            throw new \RuntimeException('merge task references must use `<entry-ref>` with both parts filled.');
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
                'Task not found for merge: %s/%s. The parent feature %s is not active.',
                $feature,
                $task,
                $feature,
            ));
        }

        throw new \RuntimeException(sprintf(
            'Task not found for merge: %s/%s. Use an active `<entry-ref>`.',
            $feature,
            $task,
        ));
    }

    private function displayResolvedMerge(
        BoardEntry $entry,
        string $caller,
        string $callerRole,
        string $type,
        string $target,
        string $entryReference,
        string $mergeTarget,
        string $equivalentCommand
    ): void {
        $this->presenter->displayLine('[Entry merge]');
        $this->presenter->displayLine('Entry-ref: ' . $entryReference);
        $this->presenter->displayLine('Reviewer: ' . ($entry->getReviewer() ?? '-'));
        $this->presenter->displayLine(sprintf('Caller: %s (%s)', $caller, $callerRole));
        $this->presenter->displayLine('Resolved type: ' . $type);
        $this->presenter->displayLine('Target: ' . $target);
        $this->presenter->displayLine('Merge target: ' . $mergeTarget);
        $this->presenter->displayLine('Equivalent command: ' . $equivalentCommand);
    }
}
