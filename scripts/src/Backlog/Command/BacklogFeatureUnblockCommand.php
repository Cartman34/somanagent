<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogCommandName;
use SoManAgent\Script\Backlog\BacklogEntryResolver;
use SoManAgent\Script\Backlog\BacklogEntryService;
use SoManAgent\Script\Backlog\BacklogGitWorkflow;
use SoManAgent\Script\Backlog\BacklogMetaValue;
use SoManAgent\Script\Backlog\BoardEntry;
use SoManAgent\Script\Backlog\PullRequestManager;
use SoManAgent\Script\Backlog\PullRequestTag;
use SoManAgent\Script\Console;

/**
 * Command for unblocking a feature.
 */
final class BacklogFeatureUnblockCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogGitWorkflow $gitWorkflow;

    private PullRequestManager $pullRequestManager;

    public function __construct(
        Console $console,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryResolver $entryResolver,
        BacklogEntryService $entryService,
        BacklogGitWorkflow $gitWorkflow,
        PullRequestManager $pullRequestManager
    ) {
        parent::__construct($console, $dryRun, $projectRoot);
        $this->entryResolver = $entryResolver;
        $this->entryService = $entryService;
        $this->gitWorkflow = $gitWorkflow;
        $this->pullRequestManager = $pullRequestManager;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $agent = $options['agent'] ?? null;
        if (!is_string($agent)) {
            throw new \RuntimeException('Option --agent is required.');
        }
        $board = $this->loadBoard();
        $feature = isset($commandArgs[0])
            ? $this->entryService->normalizeFeatureSlug($commandArgs[0])
            : $this->entryResolver->requireSingleFeatureForAgent($board, $agent)->getEntry()->getFeature();

        if ($feature === null) {
            throw new \RuntimeException('No feature available for feature-unblock.');
        }

        $match = $this->entryResolver->requireFeature($board, $feature);
        if ($match->getEntry()->getAgent() !== $agent) {
            throw new \RuntimeException("Feature {$feature} is not assigned to agent {$agent}.");
        }

        $match->getEntry()->setBlocked(false);
        $this->saveBoard($board, BacklogCommandName::FEATURE_UNBLOCK->value);

        $prNumber = $this->storedPrNumber($match->getEntry());
        if ($prNumber !== null) {
            $title = $this->buildCurrentTitle($match->getEntry());
            $this->pullRequestManager->editPrTitle($prNumber, $title);
        }

        $this->console->ok(sprintf('Removed blocked flag from feature %s', $feature));
    }

    private function storedPrNumber(BoardEntry $entry): ?int
    {
        $pr = $entry->getPr();
        if ($pr === null || $pr === BacklogMetaValue::NONE->value) {
            return null;
        }

        return (int) $pr;
    }

    private function buildCurrentTitle(BoardEntry $entry): string
    {
        $type = $this->entryService->featureStage($entry) === BacklogBoard::STAGE_APPROVED
            ? $this->determinePrType($entry)
            : PullRequestTag::WIP->value;

        return $this->buildPrTitle($type, $entry);
    }

    private function buildPrTitle(string $type, BoardEntry $entry): string
    {
        $title = sprintf('[%s] %s', $type, $entry->getText());

        return $entry->isBlocked()
            ? $this->ensureBlockedTitle($title)
            : $title;
    }

    private function ensureBlockedTitle(string $title): string
    {
        $tag = '[' . PullRequestTag::BLOCKED->value . ']';

        return str_contains($title, $tag)
            ? $title
            : $tag . ' ' . $title;
    }

    private function determinePrType(BoardEntry $entry): string
    {
        $base = $entry->getBase();
        $branch = $entry->getBranch();
        if ($base === null || $branch === null) {
            throw new \RuntimeException('Cannot determine PR type without base and branch metadata.');
        }

        $files = $this->gitWorkflow->changedFiles($base, $branch);

        if ($files === []) {
            return str_starts_with($branch, 'fix/') ? PullRequestTag::FIX->value : PullRequestTag::FEAT->value;
        }

        $docOnly = true;
        $techOnly = true;

        foreach ($files as $file) {
            if (!str_starts_with($file, 'doc/') && $file !== 'AGENTS.md') {
                $docOnly = false;
            }

            if (
                !str_starts_with($file, 'scripts/')
                && !str_starts_with($file, '.github/')
                && !in_array($file, ['AGENTS.md', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'pnpm-lock.yaml'], true)
            ) {
                $techOnly = false;
            }
        }

        if ($docOnly) {
            return PullRequestTag::DOC->value;
        }

        if ($techOnly) {
            return PullRequestTag::TECH->value;
        }

        return str_starts_with($branch, 'fix/') ? PullRequestTag::FIX->value : PullRequestTag::FEAT->value;
    }
}
