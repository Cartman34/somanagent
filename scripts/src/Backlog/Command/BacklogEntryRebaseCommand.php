<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Backlog\Service\EntryRebaseService;

/**
 * Rebases an active entry branch onto its canonical target and pushes on success.
 *
 * Target resolution:
 * - Scoped task: rebases onto the parent feature branch
 * - Root feature: rebases onto origin/main (fetched first)
 *
 * Exit behaviour:
 * - Already up to date → prints message, exits 0
 * - Rebase succeeded  → prints message, exits 0
 * - Conflict          → prints conflict file list and throws (exits non-zero)
 *
 * @see EntryRebaseService For the shared rebase logic.
 */
final class BacklogEntryRebaseCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    private EntryRebaseService $entryRebaseService;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogWorktreeService $worktreeService
     * @param EntryRebaseService $entryRebaseService
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService,
        EntryRebaseService $entryRebaseService,
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
        $this->entryRebaseService = $entryRebaseService;
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string|array<bool|string>> $options
     * @return void
     */
    public function handle(array $commandArgs, array $options): void
    {
        $slug = trim($commandArgs[0] ?? '');
        if ($slug === '') {
            throw new \RuntimeException('rebase requires <slug>. Example: php scripts/backlog.php rebase my-feature');
        }

        $dryRun = isset($options[BacklogCliOption::DRY_RUN->value]);

        $board = $this->loadBoard();

        $match = str_contains($slug, '/')
            ? $this->boardService->resolveTaskByReference($board, $slug, BacklogCommandName::REBASE->value)
            : $this->boardService->resolveFeature($board, $this->boardService->normalizeFeatureSlug($slug));

        $entry = $match->getEntry();
        $entryReference = $this->boardService->getEntryReference($entry);

        $agent = $entry->getDeveloper();
        if ($agent === null || $agent === '') {
            throw new \RuntimeException(sprintf(
                'Cannot rebase entry "%s": no developer is assigned. Assign it first with assign.',
                $slug,
            ));
        }

        $worktree = $this->worktreeService->getAgentWorktreePath($agent);
        if (!is_dir($worktree)) {
            throw new \RuntimeException(sprintf(
                'Agent worktree %s does not exist for agent %s. Restore it first with worktree-restore.',
                $worktree,
                $agent,
            ));
        }

        $this->presenter->displayLine('[Entry rebase]');
        $this->presenter->displayLine('Entry-ref: ' . $entryReference);
        $this->presenter->displayLine('Branch: ' . ($entry->getBranch() ?? '-'));

        if ($dryRun) {
            $this->presenter->displayLine(sprintf('[dry-run] Would rebase entry "%s" in worktree %s.', $entryReference, $worktree));
            return;
        }

        $result = $this->entryRebaseService->rebase($entry, $worktree, $board);

        if ($result->isConflict()) {
            $files = $result->getConflictFiles();
            if ($files !== []) {
                $this->presenter->displayLine('Conflicting files:');
                foreach ($files as $file) {
                    $this->presenter->displayLine('  - ' . $file);
                }
            }
            throw new \RuntimeException($result->getMessage());
        }

        $this->presenter->displaySuccess($result->getMessage());
    }
}
