<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Command;

use Sowapps\SoManAgent\Script\Service\GitService;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPresenter;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntry;

/**
 * Command for interactively merging all approved backlog entries.
 *
 * Lists every approved entry in board order, shows a preview (commits, diff stat, PR info),
 * then prompts the user for each entry: y=merge, n=skip, d=full diff then re-prompt, q=quit.
 * Pass --dry-run to show all previews without prompting or merging.
 */
final class BacklogUserMergeCommand extends AbstractBacklogCommand
{
    private BacklogFeatureMergeCommand $featureMergeCommand;

    private BacklogFeatureTaskMergeCommand $featureTaskMergeCommand;

    private GitService $gitService;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogFeatureMergeCommand $featureMergeCommand
     * @param BacklogFeatureTaskMergeCommand $featureTaskMergeCommand
     * @param GitService $gitService
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogFeatureMergeCommand $featureMergeCommand,
        BacklogFeatureTaskMergeCommand $featureTaskMergeCommand,
        GitService $gitService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->featureMergeCommand = $featureMergeCommand;
        $this->featureTaskMergeCommand = $featureTaskMergeCommand;
        $this->gitService = $gitService;
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $approved = $this->boardService->fetchApprovedEntries($board);

        if ($approved === []) {
            $this->presenter->displayLine('No approved entries waiting.');

            return;
        }

        $isInteractive = stream_isatty(STDIN) || (bool) getenv('BACKLOG_TEST_FORCE_INTERACTIVE');
        if (!$this->dryRun && !$isInteractive) {
            throw new \RuntimeException(
                'user-merge requires an interactive terminal (stdin is not a TTY). Use --dry-run for a non-interactive preview.'
            );
        }

        foreach ($approved as $match) {
            $entry = $match->getEntry();
            $slug = $this->resolveEntrySlug($entry);
            $kind = $this->boardService->getEntryKind($entry);
            $base = $entry->getBase() ?? '';
            $branch = $entry->getBranch() ?? '';

            $this->displayEntryPreview($entry, $slug, $kind, $base, $branch);

            if ($this->dryRun) {
                continue;
            }

            $action = $this->promptAction($slug, $base, $branch);

            if ($action === 'q') {
                return;
            }

            if ($action === 'n') {
                $this->presenter->displayLine(sprintf('Skipped: %s', $slug));
                continue;
            }

            $this->mergeEntry($entry, $slug, $kind);
        }
    }

    private function resolveEntrySlug(BoardEntry $entry): string
    {
        if ($this->boardService->checkIsTaskEntry($entry)) {
            return $this->boardService->getTaskReviewKey($entry);
        }

        return $entry->getFeature() ?? '-';
    }

    private function displayEntryPreview(
        BoardEntry $entry,
        string $slug,
        string $kind,
        string $base,
        string $branch
    ): void {
        $targetBranch = $this->boardService->checkIsTaskEntry($entry)
            ? ($entry->getFeatureBranch() ?? $entry->getFeature() ?? 'feature')
            : GitService::MAIN_BRANCH;

        $this->presenter->displayLine('');
        $this->presenter->displayLine(sprintf('[%s] %s — %s', $kind, $slug, $entry->getText()));

        foreach ($entry->getExtraLines() as $line) {
            $this->presenter->displayLine($line);
        }

        $this->presenter->displayLine(sprintf(
            'Branch: %s → %s',
            $branch !== '' ? $branch : '-',
            $targetBranch,
        ));

        $pr = $entry->getPr();
        if ($pr !== null && $pr !== '') {
            $prUrl = $this->buildPrUrl($pr);
            $this->presenter->displayLine(sprintf(
                'PR: #%s%s',
                $pr,
                $prUrl !== '' ? ' (' . $prUrl . ')' : '',
            ));
        }

        if ($base !== '' && $branch !== '') {
            $this->displayGitSummary($base, $branch);
        } else {
            $this->presenter->displayLine('Commits: (no base or branch recorded)');
            $this->presenter->displayLine('Diff stat: (no base or branch recorded)');
        }
    }

    private function displayGitSummary(string $base, string $branch): void
    {
        $log = $this->gitService->getLogOneline($base, $branch);
        $this->presenter->displayLine('Commits:');
        if ($log !== '') {
            foreach (explode("\n", $log) as $line) {
                $this->presenter->displayLine('  ' . $line);
            }
        } else {
            $this->presenter->displayLine('  (no commits)');
        }

        $stat = $this->gitService->getDiffStat($base, $branch);
        $this->presenter->displayLine('Diff stat:');
        if ($stat !== '') {
            foreach (explode("\n", $stat) as $line) {
                $this->presenter->displayLine('  ' . $line);
            }
        } else {
            $this->presenter->displayLine('  (no changes)');
        }
    }

    private function buildPrUrl(string $prNumber): string
    {
        try {
            $remoteUrl = $this->gitService->getRemoteUrl();
            if (preg_match('#github\.com[:/]([^/]+/[^/]+?)(?:\.git)?$#', $remoteUrl, $matches) === 1) {
                return 'https://github.com/' . $matches[1] . '/pull/' . $prNumber;
            }
        } catch (\RuntimeException $e) {
            // best-effort: remote URL unavailable
        }

        return '';
    }

    /**
     * Reads one character from stdin and returns the resolved action (y/n/q).
     *
     * The 'd' key triggers a full diff display and re-prompts until a definitive key is pressed.
     */
    private function promptAction(string $slug, string $base, string $branch): string
    {
        while (true) {
            $this->presenter->displayLine(sprintf('Merge %s? [y=merge / n=skip / d=diff / q=quit]', $slug));
            $rawInput = fgets(STDIN);
            if ($rawInput === false) {
                return 'q';
            }
            $key = strtolower($rawInput[0] ?? '');

            if (in_array($key, ['y', 'n', 'q'], true)) {
                return $key;
            }

            if ($key === 'd') {
                if ($base !== '' && $branch !== '') {
                    $diff = $this->gitService->getFullDiff($base, $branch);
                    $this->presenter->displayLine($diff !== '' ? $diff : '(empty diff)');
                } else {
                    $this->presenter->displayLine('(no base or branch recorded — diff not available)');
                }
            }
        }
    }

    private function mergeEntry(BoardEntry $entry, string $slug, string $kind): void
    {
        $this->presenter->displayLine(sprintf('Merging %s...', $slug));

        if ($kind === BacklogBoardService::ENTRY_KIND_TASK) {
            $this->featureTaskMergeCommand->performMerge([$slug], []);
        } else {
            $this->featureMergeCommand->performMerge([$entry->getFeature() ?? $slug], []);
        }
    }
}
