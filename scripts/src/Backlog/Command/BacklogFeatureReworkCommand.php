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
use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Console;

/**
 * Command for reworking a rejected feature.
 */
final class BacklogFeatureReworkCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogWorktreeManager $worktreeManager;

    public function __construct(
        Console $console,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryResolver $entryResolver,
        BacklogEntryService $entryService,
        BacklogWorktreeManager $worktreeManager
    ) {
        parent::__construct($console, $dryRun, $projectRoot);
        $this->entryResolver = $entryResolver;
        $this->entryService = $entryService;
        $this->worktreeManager = $worktreeManager;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $agent = $options['agent'] ?? null;
        if (!is_string($agent)) {
            throw new \RuntimeException('Option --agent is required.');
        }
        $board = $this->loadBoard();
        $match = isset($commandArgs[0])
            ? $this->entryResolver->requireFeature($board, $this->entryService->normalizeFeatureSlug($commandArgs[0]))
            : $this->entryResolver->requireSingleFeatureForAgent($board, $agent);

        $entry = $match->getEntry();
        $feature = $entry->getFeature() ?? '';
        if ($this->entryService->featureStage($entry) !== BacklogBoard::STAGE_REJECTED) {
            throw new \RuntimeException("Feature {$feature} is not in the rejected stage.");
        }

        $entry->setAgent($agent);
        $entry->setStage(BacklogBoard::STAGE_IN_PROGRESS);
        $this->saveBoard($board, BacklogCommandName::FEATURE_REWORK->value);

        $worktree = $this->worktreeManager->prepareAgentWorktree($agent);
        $this->worktreeManager->checkoutBranchInWorktree($worktree, $entry->getBranch() ?? '', false);

        $this->console->ok(sprintf('Moved feature %s back to %s', $feature, BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_PROGRESS)));
    }
}
