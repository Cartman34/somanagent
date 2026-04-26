<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogCliOption;
use SoManAgent\Script\Backlog\BacklogCommandName;
use SoManAgent\Script\Backlog\BacklogEntryResolver;
use SoManAgent\Script\Backlog\BacklogEntryService;
use SoManAgent\Script\Backlog\BacklogGitWorkflow;
use SoManAgent\Script\Backlog\BacklogMetaValue;
use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Backlog\BoardEntry;
use SoManAgent\Script\Backlog\PullRequestManager;
use SoManAgent\Script\Console;

/**
 * Command for adding a task to an active feature.
 */
final class BacklogFeatureTaskAddCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogWorktreeManager $worktreeManager;

    private BacklogGitWorkflow $gitWorkflow;

    private PullRequestManager $pullRequestManager;

    public function __construct(
        Console $console,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryResolver $entryResolver,
        BacklogEntryService $entryService,
        BacklogWorktreeManager $worktreeManager,
        BacklogGitWorkflow $gitWorkflow,
        PullRequestManager $pullRequestManager
    ) {
        parent::__construct($console, $dryRun, $projectRoot);
        $this->entryResolver = $entryResolver;
        $this->entryService = $entryService;
        $this->worktreeManager = $worktreeManager;
        $this->gitWorkflow = $gitWorkflow;
        $this->pullRequestManager = $pullRequestManager;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $agent = $options['agent'] ?? null;
        if (!is_string($agent)) {
            throw new \RuntimeException('Option --agent is required.');
        }
        $featureText = BoardEntry::parseEmptyString((string) ($options[BacklogCliOption::FEATURE_TEXT->value] ?? ''));
        if ($featureText === null) {
            throw new \RuntimeException('feature-task-add requires --feature-text.');
        }

        $board = $this->loadBoard();
        $current = $this->entryResolver->requireSingleFeatureForAgent($board, $agent);
        $feature = $current->getEntry()->getFeature();
        $target = $this->entryService->nextTodoTask($board);
        if ($target === null) {
            throw new \RuntimeException('No queued task available to add to the current feature.');
        }
        $reserved = [$target];

        $entry = $current->getEntry();
        $this->entryService->assertFeatureEntry($entry, BacklogCommandName::FEATURE_TASK_ADD->value);
        $entry->setText($featureText);
        $this->entryService->invalidateFeatureReviewState($entry);

        foreach ($reserved as $task) {
            $reservedEntry = $task->getEntry();
            $reservedEntry->setFeature(null);
            $reservedEntry->setAgent(null);
            $scopedTask = $this->entryService->extractScopedTaskMetadata($reservedEntry->getText());

            if ($scopedTask !== null) {
                if ($scopedTask['featureGroup'] !== $feature) {
                    throw new \RuntimeException(sprintf(
                        'Next queued task belongs to feature %s, not %s.',
                        $scopedTask['featureGroup'],
                        $feature,
                    ));
                }
                if ($this->entryResolver->getSingleTaskForAgent($board, $agent, false) !== null) {
                    throw new \RuntimeException(sprintf(
                        'Agent %s already owns an active task. Merge or release it before feature-task-add.',
                        $agent,
                    ));
                }

                $featureBranch = $entry->getBranch();
                $branchType = $this->entryService->detectBranchType($featureBranch);
                if ($featureBranch === null || $branchType === '') {
                    throw new \RuntimeException('Current feature metadata is incomplete: missing branch information.');
                }
                $this->entryService->assertTaskSlugAvailableForFeature($board, $entry, (string) $feature, $scopedTask['task'], BacklogCommandName::FEATURE_TASK_ADD->value);

                $taskBranch = $branchType . '/' . $feature . '--' . $scopedTask['task'];
                $taskBase = $this->gitWorkflow->branchHead($featureBranch);

                $worktree = $this->worktreeManager->prepareAgentWorktree($agent);
                $this->worktreeManager->checkoutBranchInWorktree($worktree, $taskBranch, true, $featureBranch);

                $taskEntry = new BoardEntry($scopedTask['text'], $reservedEntry->getExtraLines(), [
                    BoardEntry::META_KIND => BacklogEntryService::ENTRY_KIND_TASK,
                    BoardEntry::META_STAGE => BacklogBoard::STAGE_IN_PROGRESS,
                    BoardEntry::META_FEATURE => $feature,
                    BoardEntry::META_TASK => $scopedTask['task'],
                    BoardEntry::META_AGENT => $agent,
                    BoardEntry::META_BRANCH => $taskBranch,
                    BoardEntry::META_FEATURE_BRANCH => $featureBranch,
                    BoardEntry::META_BASE => $taskBase,
                    BoardEntry::META_PR => BacklogMetaValue::NONE->value,
                ]);
                $this->entryService->appendTaskContribution($entry, $taskEntry);

                $entries = $board->getEntries(BacklogBoard::SECTION_ACTIVE);
                $entries[] = $taskEntry;
                $board->setEntries(BacklogBoard::SECTION_ACTIVE, $entries);

                continue;
            }

            if ($this->entryService->featureContributionBlocks($entry) !== [] || $this->entryResolver->findTaskEntriesByFeature($board, (string) $feature) !== []) {
                throw new \RuntimeException(sprintf(
                    'Current feature %s already uses local child tasks. The next queued task must use [%s][task] to be attached safely.',
                    $feature,
                    $feature,
                ));
            }

            $entry->appendExtraLines(['  - ' . $reservedEntry->getText()]);
            foreach ($reservedEntry->getExtraLines() as $line) {
                $entry->appendExtraLines(['  ' . ltrim($line)]);
            }
        }

        $this->entryService->removeReservedTasks($board, $reserved);

        $this->saveBoard($board, BacklogCommandName::FEATURE_TASK_ADD->value);
        $bodyFile = isset($options[BacklogCliOption::BODY_FILE->value])
            ? (string) $options[BacklogCliOption::BODY_FILE->value]
            : null;
        if ($bodyFile !== null) {
            $this->pullRequestManager->updatePrBodyIfExists($entry->getBranch() ?? '', $bodyFile);
        }

        $this->console->ok(sprintf('Added queued task to feature %s', $feature));
    }
}
