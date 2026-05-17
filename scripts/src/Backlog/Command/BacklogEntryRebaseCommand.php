<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

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
            throw new \RuntimeException('entry-rebase requires <slug>. Example: php scripts/backlog.php entry-rebase my-feature');
        }

        $dryRun = isset($options['dry-run']);

        $board = $this->loadBoard();

        $match = str_contains($slug, '/')
            ? $this->boardService->resolveTaskByReference($board, $slug, 'entry-rebase')
            : $this->boardService->resolveFeature($board, $this->boardService->normalizeFeatureSlug($slug));

        $entry = $match->getEntry();

        $agent = $entry->getAgent();
        if ($agent === null || $agent === '') {
            throw new \RuntimeException(sprintf(
                'Cannot rebase entry "%s": no agent is assigned. Assign it first with entry-assign.',
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

        if ($dryRun) {
            $this->presenter->displayLine(sprintf('[dry-run] Would rebase entry "%s" (branch: %s) in worktree %s.', $slug, $entry->getBranch() ?? '(none)', $worktree));
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
