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
use SoManAgent\Script\Backlog\PullRequestManager;
use SoManAgent\Script\Backlog\PullRequestTag;

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
        BacklogCommandContext $context
    ) {
        parent::__construct($context);
        $this->entryResolver = $context->getEntryResolver();
        $this->entryService = $context->getEntryService();
        $this->gitWorkflow = $context->getGitWorkflow();
        $this->pullRequestManager = $context->getPullRequestManager();
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
            $title = $this->buildCurrentTitle($match->getEntry(), $this->entryService, $this->gitWorkflow);
            $this->pullRequestManager->editPrTitle($prNumber, $title);
        }

        $this->console->ok(sprintf('Removed blocked flag from feature %s', $feature));
    }
}
