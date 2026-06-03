<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Command;

use Sowapps\SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPresenter;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogEntryMetaKey;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogMetaValue;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCommandName;
/**
 * Runs the mechanical review check on the developer's active entry without changing its stage.
 *
 * On success, writes `submit-ready: yes` to the entry's extra metadata and saves the board.
 * On failure, clears `submit-ready` from the entry's extra metadata and saves the board.
 *
 * The entry must be in `development` stage. No rebase is performed.
 * The stage is never changed; `review-request` remains the authority for the transition to `review`.
 */
final class BacklogSubmitCheckCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogWorktreeService $worktreeService
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService,
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $agent = $this->requireCallerAgent();

        $board = $this->loadBoard();
        $activeEntries = $this->boardService->findActiveEntriesByAgent($board, $agent);

        if ($activeEntries === []) {
            throw new \RuntimeException(
                "Developer {$agent} has no active entry.\n" .
                "Run `php scripts/backlog.php start` to start one."
            );
        }

        $entry = $activeEntries[0]->getEntry();

        if ($entry->getStage() !== BacklogBoard::STAGE_IN_PROGRESS) {
            throw new \RuntimeException(sprintf(
                "submit-check requires stage=%s. Current stage: %s.",
                BacklogBoard::STAGE_IN_PROGRESS,
                $entry->getStage() ?? 'unknown',
            ));
        }

        $worktree = $this->worktreeService->prepareFeatureAgentWorktree($entry);

        $passed = false;
        try {
            $base = $entry->getBase();
            $this->worktreeService->runReviewScript($worktree, $base !== '' ? $base : null);
            $passed = true;
        } catch (\RuntimeException) {
            // Review script failed; $passed remains false.
        }

        $extra = $entry->getExtraMetadata();
        if ($passed) {
            $extra[BacklogEntryMetaKey::SUBMIT_READY->value] = BacklogMetaValue::YES->value;
        } else {
            unset($extra[BacklogEntryMetaKey::SUBMIT_READY->value]);
        }
        $entry->setExtraMetadata($extra);

        $this->saveBoard($board, BacklogCommandName::SUBMIT_CHECK->value);

        if ($passed) {
            $label = $entry->getTask() !== null
                ? sprintf('Task %s/%s', $entry->getFeature(), $entry->getTask())
                : sprintf('Feature %s', $entry->getFeature());
            $this->presenter->displaySuccess(sprintf(
                '%s passed submit-check — submit-ready: yes.',
                $label,
            ));
        } else {
            throw new \RuntimeException(
                "submit-check failed. Fix the findings reported above, commit, and run submit-check again."
            );
        }
    }
}
