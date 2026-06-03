<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Command;

use Sowapps\SoManAgent\Script\Service\GitService;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPresenter;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntry;

/**
 * Command for updating the recorded Git base of an active backlog entry.
 */
final class BacklogBaseUpdateCommand extends AbstractBacklogCommand
{
    private GitService $gitService;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param GitService $gitService
     * @return void
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        GitService $gitService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->gitService = $gitService;
    }

        /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     * @return void
     */
    public function handle(array $commandArgs, array $options): void
    {
        $target = trim($commandArgs[0] ?? '');
        if ($target === '') {
            throw new \RuntimeException('base-update requires <entry-ref>.');
        }

        $board = $this->loadBoard();
        $match = str_contains($target, '/')
            ? $this->boardService->resolveTaskByReference($board, $target, BacklogCommandName::BASE_UPDATE->value)
            : $this->boardService->resolveFeature($board, $this->boardService->normalizeFeatureSlug($target));

        $entry = $match->getEntry();
        $branch = $entry->getBranch();
        if ($branch === null || $branch === '') {
            throw new \RuntimeException('Cannot update base: entry metadata is missing branch.');
        }
        if (!$this->gitService->checkRefExists($branch)) {
            throw new \RuntimeException(sprintf('Cannot update base: branch ref does not exist: %s.', $branch));
        }

        $requestedBase = $options[BacklogCliOption::BASE->value] ?? null;
        if ($requestedBase !== null && !is_string($requestedBase)) {
            throw new \RuntimeException('Option --base must be a string.');
        }

        $base = $requestedBase !== null && trim($requestedBase) !== ''
            ? trim($requestedBase)
            : $this->resolveDefaultBase($entry, $branch);

        if (!$this->gitService->checkRefExists($base)) {
            throw new \RuntimeException(sprintf('Cannot update base: ref does not exist: %s.', $base));
        }
        if (!$this->gitService->checkIsAncestor($base, $branch)) {
            throw new \RuntimeException(sprintf('Cannot update base: ref %s is not an ancestor of %s.', $base, $branch));
        }

        $baseCommit = $this->gitService->getBranchHead($base);
        $previousBase = $entry->getBase() ?? '-';
        $entry->setBase($baseCommit);
        $this->saveBoard($board, BacklogCommandName::BASE_UPDATE->value);

        $this->presenter->displaySuccess(sprintf('Updated base for %s: %s -> %s', $target, $previousBase, $baseCommit));
    }

    private function resolveDefaultBase(BoardEntry $entry, string $branch): string
    {
        if ($this->boardService->checkIsTaskEntry($entry)) {
            $featureBranch = $entry->getFeatureBranch();
            if ($featureBranch === null || $featureBranch === '') {
                throw new \RuntimeException('Cannot update task base automatically: entry metadata is missing feature-branch.');
            }
            if (!$this->gitService->checkRefExists($featureBranch)) {
                throw new \RuntimeException(sprintf('Cannot update task base automatically: feature branch ref does not exist: %s.', $featureBranch));
            }

            return $this->gitService->getMergeBase($featureBranch, $branch);
        }

        $this->gitService->updateMainBranch();

        return $this->gitService->getMergeBase(GitService::ORIGIN_REMOTE . '/' . GitService::MAIN_BRANCH, $branch);
    }
}
