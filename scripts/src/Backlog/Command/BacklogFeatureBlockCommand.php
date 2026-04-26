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
use SoManAgent\Script\Backlog\PullRequestService;
use SoManAgent\Script\Backlog\PullRequestTag;

/**
 * Command for blocking a feature.
 */
final class BacklogFeatureBlockCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogGitWorkflow $gitWorkflow;

    private PullRequestService $pullRequestService;

    public function __construct(BacklogCommandContext $context)
    {
        parent::__construct($context);
        $this->entryResolver = $context->getEntryResolver();
        $this->entryService = $context->getEntryService();
        $this->gitWorkflow = $context->getGitWorkflow();
        $this->pullRequestService = $context->getPullRequestService();
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
            throw new \RuntimeException('No feature available for feature-block.');
        }

        $match = $this->entryResolver->requireFeature($board, $feature);
        $entry = $match->getEntry();
        $entry->setBlocked(true);
        $this->saveBoard($board, BacklogCommandName::FEATURE_BLOCK->value);

        $prNumber = $this->pullRequestService->findPrNumberByBranch($entry->getBranch() ?? '');
        if ($prNumber !== null) {
            $type = $this->entryService->featureStage($entry) === BacklogBoard::STAGE_APPROVED ? $this->pullRequestService->determinePrType($entry, $this->gitWorkflow) : PullRequestTag::WIP->value;
            $title = $this->pullRequestService->ensureBlockedTitle($this->pullRequestService->buildPrTitle($type, $entry));
            $this->pullRequestService->editPrTitle($prNumber, $title);
        }

        $this->console->ok(sprintf('Marked feature %s as blocked', $feature));
    }
}
