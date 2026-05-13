<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog;

use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\GitHub\Enum\GitHubCommandName;
use SoManAgent\Script\TextSlugger;
use SoManAgent\Script\Service\GitService;

/**
 * Test driver for backlog script workflow testing
 *
 * Provides a fluent interface to execute backlog commands and verify test outcomes.
 */
final class BacklogScriptTestDriver
{
    private BacklogScriptTestContext $context;
    private ConsoleClient $consoleClient;
    private Console $console;

    /**
     * @param BacklogScriptTestContext $context Test context containing configuration
     * @param ConsoleClient $consoleClient Console client for command execution
     * @param Console $console Console instance for output
     */
    public function __construct(BacklogScriptTestContext $context, ConsoleClient $consoleClient, Console $console)
    {
        $this->context = $context;
        $this->consoleClient = $consoleClient;
        $this->console = $console;
    }

    /**
     * Initialize test artifacts
     */
    public function initializeArtifacts(): void
    {
        $this->resetArtifacts();
    }

    /**
     * Reset test artifacts to clean state
     */
    public function resetArtifacts(): void
    {
        $this->writeFile($this->context->boardPath, <<<MD
# Backlog board

## Usage rules

- Temporary test file for scripts/test-backlog-workflow.php.
- Do not use this file as a production backlog.

## To do

## In progress

## Suggestions

MD);

        $this->writeFile($this->context->reviewPath, <<<MD
# Current review

## Usage rules

- Temporary test file for scripts/test-backlog-workflow.php.
- Do not use this file as a production review.

## Current review

No review in progress.

MD);
    }

    /**
     * Finalize and cleanup test artifacts
     */
    public function finalizeArtifacts(): void
    {
        $this->cleanupTrackedResources();

        if ($this->context->keepArtifacts) {
            $this->console->line(sprintf(
                'Kept test artifacts: %s and %s',
                $this->relativePath($this->context->boardPath),
                $this->relativePath($this->context->reviewPath),
            ));

            return;
        }

        if (is_file($this->context->boardPath)) {
            unlink($this->context->boardPath);
        }
        if (is_file($this->context->reviewPath)) {
            unlink($this->context->reviewPath);
        }
    }

    /**
     * Run help checks: --help is the only accepted form; the old `help` command is gone.
     */
    public function runHelpChecks(): void
    {
        $this->assertOutputContains($this->runBacklog([]), 'Commands:');
        $this->assertOutputContains($this->runBacklog(['status', '--help']), 'status');
        $this->assertOutputContains($this->runBacklog(['review-next', '--help']), 'review-next');
        $this->assertOutputContains($this->runBacklog(['work-start', '--help']), 'work-start');
        $this->assertOutputContains($this->runBacklog(['entry-merge', '--help']), 'entry-merge');
        $this->assertOutputContains($this->runBacklog(['review-request', '--help']), 'review-request');
        $this->assertOutputContains($this->runBacklog(['review-check', '--help']), 'review-check');
        $this->assertOutputContains($this->runBacklog(['review-approve', '--help']), 'review-approve');
        $this->assertOutputContains($this->runBacklog(['review-reject', '--help']), 'review-reject');
        // Regression: `help` and `help <command>` must be unknown commands, not silent aliases.
        $this->assertCommandIsUnknown('help');
        $this->assertBacklogFails(
            ['help', 'work-start'],
            'Unknown command: help. Run with --help for the list of available commands.',
        );
    }

    /**
     * Verify --force-current-worktree is stripped from argv before backlog argument parsing.
     *
     * Regression: a leftover --force-current-worktree was consumed as the value of the
     * preceding option by parseArgs, which made the requested command disappear and the
     * runner respond with the global help (or "Unknown command" depending on placement).
     */
    public function runForceCurrentWorktreeFlagChecks(): void
    {
        $this->assertOutputContains(
            $this->runBacklog(['--force-current-worktree', 'status', '--help']),
            'Print the current backlog and worktree status',
        );
        $this->assertOutputContains(
            $this->runBacklog(['--force-current-worktree', 'work-start', '--help']),
            'work-start',
        );
        $this->assertOutputContains(
            $this->runBacklog(['status', '--force-current-worktree', '--help']),
            'Print the current backlog and worktree status',
        );
    }

    /**
     * Run option equals syntax checks
     */
    public function runOptionEqualsChecks(): void
    {
        $this->assertOutputContains($this->runBacklog(['status', '--agent=' . $this->context->agentPrimary]), '[Task]');

        $bodyFile = $this->createBodyFile('test-option-equals-body.md', ['Missing task should fail after parsing body-file.']);
        $this->assertBacklogFails(
            ['review-reject', 'missing-feature/missing-task', '--body-file=' . $bodyFile],
            'missing-feature/missing-task',
            ['SOMANAGER_AGENT' => $this->context->agentPrimary],
        );
    }

    /**
     * Verify the strict CLI option validator rejects unknown options on every entry path.
     *
     * Covers both the `--option=value` and `--option value` forms, the typo path
     * (`--as=<code>` must fail rather than be silently dropped), and confirms that
     * documented options (`--body-file`, `--branch-type`) plus
     * global ones (`--dry-run`, `--verbose`) remain accepted.
     */
    public function runStrictOptionsChecks(): void
    {
        $this->assertBacklogFails(
            ['status', '--as=' . $this->context->agentPrimary],
            'Unknown option(s) for command `status`: --as',
        );
        $this->assertBacklogFails(
            ['status', '--as', $this->context->agentPrimary],
            'Unknown option(s) for command `status`: --as',
        );
        $this->assertBacklogFails(
            ['work-start', '--unknown-flag'],
            'Unknown option(s) for command `work-start`: --unknown-flag',
            ['SOMANAGER_AGENT' => $this->context->agentPrimary],
        );
        $this->assertBacklogFails(
            ['--unknown-global'],
            'Unknown global option(s): --unknown-global',
        );

        $this->assertOutputContains(
            $this->runBacklog(['status', '--agent=' . $this->context->agentPrimary, '--dry-run']),
            '[Task]',
        );
        $this->assertOutputContains(
            $this->runBacklog(['status', '--agent', $this->context->agentPrimary, '--verbose']),
            '[Task]',
        );
        $this->assertBacklogFails(
            ['review-check', '--as=' . $this->context->agentPrimary],
            'Unknown option(s) for command `review-check`: --as',
        );
    }

    /**
     * @param string $text Task text to create
     */
    public function createTodoTask(string $text): void
    {
        $this->runBacklog(['task-create', $text]);
    }

    /**
     * Remove the first todo task using its stable reference.
     */
    public function removeFirstTodoTask(): void
    {
        $service = $this->boardService();
        $entries = $service->loadBoard($this->context->boardPath)->getEntries(BacklogBoard::SECTION_TODO);
        if ($entries === []) {
            throw new \RuntimeException('removeFirstTodoTask: no queued todo task to remove.');
        }
        $reference = $service->computeQueuedEntryReference($entries[0]);
        $this->runBacklog(['task-remove', $reference]);
    }

    /**
     * Remove a queued todo task by its stable reference.
     *
     * @param string $reference <entry-ref>
     */
    public function removeTodoTask(string $reference): void
    {
        $this->runBacklog(['task-remove', $reference]);
    }

    /**
     * Asserts that `task-remove` fails with the given message.
     *
     * @param string $reference Reference passed to task-remove
     * @param string $needle Expected substring of the failure output
     */
    public function assertTaskRemoveFails(string $reference, string $needle): void
    {
        $this->assertBacklogFails(['task-remove', $reference], $needle);
    }

    /**
     * @param string $needle String that should be present in todo list output
     */
    public function assertTodoContains(string $needle): void
    {
        $this->assertOutputContains($this->runBacklog(['todo-list']), $needle);
    }

    /**
     * @param string $agent Agent identifier for the worktree
     * @param ?string $target Optional explicit `<entry-ref>`
     * @return string Command output from work-start
     */
    public function startNextFeature(string $agent, ?string $target = null): string
    {
        $worktreePath = $this->managedWorktreePath($agent);
        if ((is_dir($worktreePath) || is_file($worktreePath)) && !$this->context->hasWorktree($worktreePath)) {
            throw new \RuntimeException(sprintf(
                'Refusing to use pre-existing unmanaged test worktree: %s',
                $this->relativePath($worktreePath),
            ));
        }

        $args = ['work-start'];
        if ($target !== null) {
            $args[] = $target;
        }
        $output = $this->runBacklog($args, ['SOMANAGER_AGENT' => $agent]);
        $this->context->recordWorktree($worktreePath);

        return $output;
    }

    /**
     * Runs `todo-list` and returns its output.
     */
    public function todoList(): string
    {
        return $this->runBacklog(['todo-list']);
    }

    /**
     * Runs `review-list` and returns its output.
     */
    public function reviewList(): string
    {
        return $this->runBacklog(['review-list']);
    }

    /**
     * @param string $output work-start output to check
     * @param string $needle String that should be present in output
     */
    public function assertFeatureStartOutputContains(string $output, string $needle): void
    {
        $this->assertOutputContains($output, $needle);
    }

    /**
     * @param string $reference Entry reference (`<entry-ref>`) to assign
     * @param string $agent Agent to assign the entry to
     */
    public function assignFeatureAsManager(string $reference, string $agent): void
    {
        $this->runBacklog(['feature-assign', $reference, '--agent', $agent], ['SOMANAGER_ROLE' => 'manager']);
    }

    /**
     * Asserts that `feature-assign` fails with the given message.
     *
     * @param string $reference Entry reference (`<entry-ref>`) to assign
     * @param string $agent Agent code passed via `--agent`
     * @param array<string, string> $env Environment variables (typically SOMANAGER_ROLE / SOMANAGER_AGENT)
     * @param string $needle Expected substring of the failure output
     */
    public function assertAssignFeatureFails(string $reference, string $agent, array $env, string $needle): void
    {
        $this->assertBacklogFails(['feature-assign', $reference, '--agent', $agent], $needle, $env);
    }

    /**
     * Unassigns an entry (feature or task) using the manager role.
     *
     * @param ?string $reference Optional explicit reference (`<entry-ref>`).
     *                           When null, the script falls back to the agent's single active entry.
     * @param string $agent Caller agent code passed via `--agent`.
     */
    public function unassignEntryAsManager(?string $reference, string $agent): void
    {
        $arguments = ['entry-unassign'];
        if ($reference !== null) {
            $arguments[] = $reference;
        }
        $arguments[] = '--agent';
        $arguments[] = $agent;
        $this->runBacklog($arguments, ['SOMANAGER_ROLE' => 'manager']);
    }

    /**
     * Asserts that `entry-unassign` fails with the given message when invoked under the
     * provided role/agent environment.
     *
     * @param ?string $reference Optional explicit reference (`<entry-ref>`).
     * @param string $agent Caller agent code passed via `--agent`.
     * @param array<string, string> $env Environment variables (typically SOMANAGER_ROLE / SOMANAGER_AGENT).
     * @param string $needle Expected substring of the failure output.
     */
    public function assertUnassignEntryFails(?string $reference, string $agent, array $env, string $needle): void
    {
        $arguments = ['entry-unassign'];
        if ($reference !== null) {
            $arguments[] = $reference;
        }
        $arguments[] = '--agent';
        $arguments[] = $agent;
        $this->assertBacklogFails($arguments, $needle, $env);
    }

    /**
     * @param string $agent Agent releasing the feature
     * @param string $feature Feature name to release
     */
    public function releaseFeature(string $agent, string $feature): void
    {
        $this->runBacklog(['feature-release', $feature], ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param string $agent Agent attempting the release
     * @param string $feature Feature name to release
     * @param string $needle Expected error message
     */
    public function assertReleaseFeatureFails(string $agent, string $feature, string $needle): void
    {
        $this->assertBacklogFails(['feature-release', $feature], $needle, ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param string $feature Feature name to close
     */
    public function closeFeature(string $feature): void
    {
        $this->runBacklog(['feature-close', $feature]);
    }

    /**
     * @param string $featureOrAgent Feature name or agent identifier
     * @param bool $isAgent Whether the first parameter is an agent
     * @return string Status command output
     */
    public function status(string $featureOrAgent, bool $isAgent = false): string
    {
        return $isAgent
            ? $this->runBacklog(['status', '--agent', $featureOrAgent])
            : $this->runBacklog(['status', $featureOrAgent]);
    }

    /**
     * @param string $agent Agent requesting the review
     */
    public function requestTaskReview(string $agent): void
    {
        $this->runBacklog(['review-request'], ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param string $reviewer Reviewer agent code (required by review-next)
     * @param ?string $target Optional explicit `<entry-ref>`
     * @return string Output from review-next command
     */
    public function reviewNext(string $reviewer, ?string $target = null): string
    {
        $args = ['review-next'];
        if ($target !== null) {
            $args[] = $target;
        }

        return $this->runBacklog($args, ['SOMANAGER_AGENT' => $reviewer]);
    }

    /**
     * @param string $reviewer Reviewer agent code
     * @param string $needle Expected error message
     * @param ?string $target Optional explicit reference passed to review-next
     */
    public function assertReviewNextFails(string $reviewer, string $needle, ?string $target = null): void
    {
        $args = ['review-next'];
        if ($target !== null) {
            $args[] = $target;
        }
        $this->assertBacklogFails($args, $needle, ['SOMANAGER_AGENT' => $reviewer]);
    }

    /**
     * @param string $reviewer Reviewer agent code
     * @param string $reference Optional entry reference; empty to omit
     */
    public function reviewCancel(string $reviewer, string $reference = ''): void
    {
        $args = ['review-cancel'];
        if ($reference !== '') {
            $args[] = $reference;
        }
        $this->runBacklog($args, ['SOMANAGER_AGENT' => $reviewer]);
    }

    /**
     * @param string $reviewer Reviewer agent code
     * @param string $reference Optional entry reference; empty to omit
     * @param string $needle Expected error message
     */
    public function assertReviewCancelFails(string $reviewer, string $reference, string $needle): void
    {
        $args = ['review-cancel'];
        if ($reference !== '') {
            $args[] = $reference;
        }
        $this->assertBacklogFails($args, $needle, ['SOMANAGER_AGENT' => $reviewer]);
    }

    /**
     * @param string $reviewer Reviewer agent code
     * @param string $reference <entry-ref>
     * @return string Command output
     */
    public function reviewCheck(string $reviewer, string $reference): string
    {
        return $this->runBacklog(['review-check', $reference], ['SOMANAGER_AGENT' => $reviewer]);
    }

    /**
     * @param string $reviewer Reviewer agent code
     * @param string $reference <entry-ref>
     * @param string $needle Expected error message
     */
    public function assertReviewCheckFails(string $reviewer, string $reference, string $needle): void
    {
        $this->assertBacklogFails(['review-check', $reference], $needle, ['SOMANAGER_AGENT' => $reviewer]);
    }

    /**
     * Approve a feature via the unified review-approve command, tracking branch and PR.
     *
     * @param string $reviewer Reviewer agent code
     * @param string $feature Feature slug
     * @param string $bodyFile Path to approve body file
     */
    public function approveFeatureViaUnifiedCommand(string $reviewer, string $feature, string $bodyFile): void
    {
        $this->runBacklog(['review-approve', $feature, '--body-file', $bodyFile], ['SOMANAGER_AGENT' => $reviewer]);
        $entry = $this->requireFeatureEntry($feature);
        $branch = $entry->getBranch();
        if ($branch !== null && $branch !== '') {
            $this->context->recordRemoteBranch($branch);
        }
        $prNumber = $entry->getPr();
        if (!$this->context->dryRun && $prNumber === null) {
            throw new \RuntimeException('Expected review-approve to record the pull request number.');
        }
        $this->context->setPullRequestNumber($prNumber !== null ? (int) $prNumber : null);
    }

    /**
     * Approve a task via the unified review-approve command.
     *
     * @param string $reviewer Reviewer agent code
     * @param string $reference Fully qualified task reference (`<entry-ref>`)
     */
    public function approveTaskViaUnifiedCommand(string $reviewer, string $reference): void
    {
        $this->runBacklog(['review-approve', $reference], ['SOMANAGER_AGENT' => $reviewer]);
    }

    /**
     * @param string $reviewer Reviewer agent code
     * @param string $reference Feature slug or task reference
     * @param string|null $bodyFile Path to body file (null to omit)
     * @param string $needle Expected error message
     */
    public function assertReviewApproveFails(string $reviewer, string $reference, ?string $bodyFile, string $needle): void
    {
        $args = ['review-approve', $reference];
        if ($bodyFile !== null) {
            $args[] = '--body-file';
            $args[] = $bodyFile;
        }
        $this->assertBacklogFails($args, $needle, ['SOMANAGER_AGENT' => $reviewer]);
    }

    /**
     * Reject a feature or task via the unified review-reject command.
     *
     * @param string $reviewer Reviewer agent code
     * @param string $reference <entry-ref>
     * @param string $bodyFile Path to reject body file
     */
    public function rejectReviewViaUnifiedCommand(string $reviewer, string $reference, string $bodyFile): void
    {
        $this->runBacklog(['review-reject', $reference, '--body-file', $bodyFile], ['SOMANAGER_AGENT' => $reviewer]);
    }

    /**
     * @param string $reviewer Reviewer agent code
     * @param string $reference Feature slug or task reference
     * @param string|null $bodyFile Path to body file (null to omit --body-file)
     * @param string $needle Expected error message
     */
    public function assertReviewRejectFails(string $reviewer, string $reference, ?string $bodyFile, string $needle): void
    {
        $args = ['review-reject', $reference];
        if ($bodyFile !== null) {
            $args[] = '--body-file';
            $args[] = $bodyFile;
        }
        $this->assertBacklogFails($args, $needle, ['SOMANAGER_AGENT' => $reviewer]);
    }

    /**
     * Assert that the given command name is rejected as unknown, with no specific legacy text.
     *
     * @param string $name Command name expected to be unknown
     */
    public function assertCommandIsUnknown(string $name): void
    {
        $this->assertBacklogFails(
            [$name],
            sprintf('Unknown command: %s. Run with --help for the list of available commands.', $name),
        );
    }

    public function rework(string $agent, string $reference = ''): void
    {
        $args = ['rework'];
        if ($reference !== '') {
            $args[] = $reference;
        }
        $this->runBacklog($args, ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param string $agent Agent owning the entry
     * @param string $reference Optional positional reference, empty to omit
     * @param string $needle Error fragment expected in the failure output
     */
    public function assertReworkFails(string $agent, string $reference, string $needle): void
    {
        $args = ['rework'];
        if ($reference !== '') {
            $args[] = $reference;
        }
        $this->assertBacklogFails($args, $needle, ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * Assert the active task entry has the expected stage value.
     *
     * @param string $reference Task reference (<entry-ref>)
     * @param string $expectedStage One of BacklogBoard::STAGE_* constants
     */
    public function assertTaskStage(string $reference, string $expectedStage): void
    {
        $service = $this->boardService();
        $match = $service->resolveTaskByReference($this->board(), $reference, 'assertTaskStage');
        $actual = $service->getFeatureStage($match->getEntry());
        if ($actual !== $expectedStage) {
            throw new \RuntimeException(sprintf(
                'Expected task %s to be in stage %s, got %s.',
                $reference,
                $expectedStage,
                $actual,
            ));
        }
    }

    /**
     * Run review-notes with optional agent and optional positional reference.
     *
     * @param string|null $agent Agent owning the entry, or null when only a positional reference is used
     * @param string|null $reference Positional reference (<entry-ref>), or null
     * @return string Command output for further assertions
     */
    public function reviewNotes(?string $agent, ?string $reference): string
    {
        $args = ['review-notes'];
        if ($agent !== null) {
            $args[] = '--agent';
            $args[] = $agent;
        }
        if ($reference !== null) {
            $args[] = $reference;
        }

        return $this->runBacklog($args);
    }

    /**
     * Asserts that review-notes fails with the expected error message.
     *
     * @param string|null $agent Agent owning the entry, or null when only a positional reference is used
     * @param string|null $reference Positional reference, or null
     * @param string $needle Error fragment expected in the failure output
     */
    public function assertReviewNotesFails(?string $agent, ?string $reference, string $needle): void
    {
        $args = ['review-notes'];
        if ($agent !== null) {
            $args[] = '--agent';
            $args[] = $agent;
        }
        if ($reference !== null) {
            $args[] = $reference;
        }
        $this->assertBacklogFails($args, $needle);
    }

    /**
     * Asserts that the given output contains the needle. Public for campaigns that compose reviewNotes() with custom checks.
     */
    public function assertContains(string $output, string $needle): void
    {
        $this->assertOutputContains($output, $needle);
    }

    /**
     * @param string $reference Task reference to merge
     */
    public function mergeTask(string $reference): void
    {
        $output = $this->runBacklog(['entry-merge', $reference], ['SOMANAGER_AGENT' => 'test-reviewer']);
        $this->assertOutputContains($output, 'Resolved type: task');
        $this->assertOutputContains($output, 'Equivalent command: feature-task-merge ' . $reference);
    }

    /**
     * Assert that the deprecated feature-task-merge command is no longer public.
     *
     * @param string $reference Task reference
     */
    public function mergeTaskWithLegacyCommand(string $reference): void
    {
        $this->assertBacklogFails(
            ['feature-task-merge', $reference],
            'feature-task-merge is no longer a public command.',
        );
    }

    /**
     * Assert entry-merge refuses to infer a task from the developer agent option.
     *
     * @param string $agent Developer agent that owns a task
     */
    public function assertEntryMergeWithoutReferenceFails(string $agent): void
    {
        $this->assertBacklogFails(
            ['entry-merge'],
            'entry-merge requires <entry-ref>.',
            ['SOMANAGER_AGENT' => $agent],
        );
    }

    /**
     * @param string $reference Feature or task reference
     */
    public function assertEntryMergeRequiresReviewer(string $reference): void
    {
        $this->assertBacklogFails(
            ['entry-merge', $reference],
            'Command requires SOMANAGER_AGENT=<code>.',
            ['SOMANAGER_AGENT' => ''],
        );
    }

    /**
     * @param string $task Task slug without the parent feature
     */
    public function assertEntryMergeShortTaskReferenceFails(string $task): void
    {
        $this->assertBacklogFails(
            ['entry-merge', $task],
            'entry-merge refuses short task reference',
            ['SOMANAGER_AGENT' => 'test-reviewer'],
        );
    }

    /**
     * @param string $reference Full task reference
     * @param string $bodyFile Body file that should be rejected on task merges
     */
    public function assertEntryMergeTaskBodyFileFails(string $reference, string $bodyFile): void
    {
        $this->assertBacklogFails(
            ['entry-merge', $reference, '--body-file', $bodyFile],
            'entry-merge accepts --body-file only for feature merges.',
            ['SOMANAGER_AGENT' => 'test-reviewer'],
        );
    }

    /**
     * @param string $agent Agent whose active entry is renamed
     * @param string $newText New entry text
     */
    public function renameEntry(string $agent, string $newText): void
    {
        $this->runBacklog(['entry-rename', $newText], ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param string $agent Agent requesting the review
     */
    public function requestFeatureReview(string $agent): void
    {
        $this->runBacklog(['review-request'], ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param string $agent Agent blocking the feature
     * @param string $feature Feature name to block
     */
    public function blockFeature(string $agent, string $feature): void
    {
        $this->runBacklog(['feature-block', $feature], ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param string $agent Agent unblocking the feature
     * @param string $feature Feature name to unblock
     */
    public function unblockFeature(string $agent, string $feature): void
    {
        $this->runBacklog(['feature-unblock', $feature], ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param string $feature Feature name to merge
     * @param string|null $bodyFile Path to merge body file
     */
    public function mergeFeature(string $feature, ?string $bodyFile = null): void
    {
        $arguments = ['entry-merge', $feature];
        if ($bodyFile !== null) {
            $arguments[] = '--body-file';
            $arguments[] = $bodyFile;
        }

        $output = $this->runBacklog($arguments, ['SOMANAGER_AGENT' => 'test-reviewer']);
        $this->assertOutputContains($output, 'Resolved type: feature');
        $this->assertOutputContains($output, 'Equivalent command: feature-merge ' . $feature);
        $this->context->markPullRequestMerged();
    }

    /**
     * Assert that the deprecated feature-merge command is no longer public.
     *
     * @param string $feature Feature slug
     */
    public function mergeFeatureWithLegacyCommand(string $feature): void
    {
        $this->assertBacklogFails(
            ['feature-merge', $feature],
            'feature-merge is no longer a public command.',
        );
    }

    /**
     * Assert that the deprecated feature-merge command is no longer public.
     *
     * @param string $feature Feature slug
     */
    public function assertFeatureMergeIsDeprecated(string $feature): void
    {
        $this->assertBacklogFails(
            ['feature-merge', $feature],
            'feature-merge is no longer a public command.',
        );
    }

    /**
     * @param string $agent Agent committing the change
     * @param string $feature Feature name
     * @param string $fileName Name of the file to create
     */
    public function commitFeatureChange(string $agent, string $feature, string $fileName): void
    {
        $worktreePath = $this->managedWorktreePath($agent);
        $relativeFilePath = $fileName;
        $absoluteFilePath = $worktreePath . '/' . $relativeFilePath;

        $parent = dirname($absoluteFilePath);
        if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
            throw new \RuntimeException("Unable to create worktree test directory: {$parent}");
        }

        $contents = sprintf(
            "feature=%s\nagent=%s\nts=%s\n",
            $feature,
            $agent,
            date('c'),
        );
        if (file_put_contents($absoluteFilePath, $contents) === false) {
            throw new \RuntimeException("Unable to write worktree test file: {$absoluteFilePath}");
        }

        $this->runGitInWorktree($worktreePath, sprintf('add %s', escapeshellarg($relativeFilePath)));
        $this->runGitInWorktree($worktreePath, sprintf(
            'commit -m %s',
            escapeshellarg(sprintf('[%s] Add workflow test artifact', $feature)),
        ));
    }

    /**
     * @param string $agent Agent committing the change
     * @param string $feature Feature name
     * @param string $fileName Name of the file to create and revert
     */
    public function commitAndRevertFeatureChange(string $agent, string $feature, string $fileName): void
    {
        $this->commitFeatureChange($agent, $feature, $fileName);

        $worktreePath = $this->managedWorktreePath($agent);
        $absoluteFilePath = $worktreePath . '/' . $fileName;
        if (!is_file($absoluteFilePath)) {
            throw new \RuntimeException("Expected worktree test file not found: {$absoluteFilePath}");
        }
        if (!unlink($absoluteFilePath)) {
            throw new \RuntimeException("Unable to remove worktree test file: {$absoluteFilePath}");
        }

        $this->runGitInWorktree($worktreePath, sprintf('add -A %s', escapeshellarg($fileName)));
        $this->runGitInWorktree($worktreePath, sprintf(
            'commit -m %s',
            escapeshellarg(sprintf('[%s] Revert workflow test artifact', $feature)),
        ));
    }

    /**
     * @param string $feature Feature name to track
     * @return string Branch name
     */
    public function trackFeatureBranch(string $feature): string
    {
        $entry = $this->requireFeatureEntry($feature);
        $branch = $entry->getBranch() ?? '';
        if ($branch === '') {
            throw new \RuntimeException("Feature {$feature} has no branch metadata in test backlog.");
        }

        $this->context->recordLocalBranch($branch);

        return $branch;
    }

    /**
     * @return string Created branch name
     */
    public function createRemoteTestBaseBranch(): string
    {
        $branch = sprintf('test/backlog-workflow-%s-%04d', date('Ymd-His'), random_int(1000, 9999));
        $this->runGitRoot(sprintf('fetch origin %s', GitService::MAIN_BRANCH));
        $this->runGitRoot(sprintf(
            'branch %s %s',
            escapeshellarg($branch),
            escapeshellarg(GitService::ORIGIN_REMOTE . '/' . GitService::MAIN_BRANCH),
        ));
        $this->context->recordLocalBranch($branch);
        $this->runGitRoot(sprintf('push -u origin %s', escapeshellarg($branch)));
        $this->context->recordRemoteBranch($branch);
        $this->context->setPrBaseBranch($branch);

        return $branch;
    }

    /**
     * @param string $feature Feature name that should exist
     */
    public function assertActiveFeatureExists(string $feature): void
    {
        $board = $this->board();
        if ($this->boardService()->findParentFeatureEntry($board, $feature) === null) {
            throw new \RuntimeException("Expected active feature not found in test backlog: {$feature}");
        }
    }

    /**
     * @param string $feature Feature name that should not exist
     */
    public function assertActiveFeatureMissing(string $feature): void
    {
        $board = $this->board();
        if ($this->boardService()->findParentFeatureEntry($board, $feature) !== null) {
            throw new \RuntimeException("Unexpected active feature still present in test backlog: {$feature}");
        }
    }

    /**
     * @param string $needle String that should be present in review content
     */
    public function assertReviewContains(string $needle): void
    {
        $contents = (string) file_get_contents($this->context->reviewPath);
        if (!str_contains($contents, $needle)) {
            throw new \RuntimeException("Expected review content not found: {$needle}");
        }
    }

    /**
     * @param string $needle String that should not be present in review content
     */
    public function assertReviewMissing(string $needle): void
    {
        $contents = (string) file_get_contents($this->context->reviewPath);
        if (str_contains($contents, $needle)) {
            throw new \RuntimeException("Unexpected review content still present: {$needle}");
        }
    }

    /**
     * @param string $featureOrAgent Feature name or agent identifier
     * @param string $needle String that should be present in status output
     * @param bool $isAgent Whether the first parameter is an agent
     */
    public function assertStatusContains(string $featureOrAgent, string $needle, bool $isAgent = false): void
    {
        $this->assertOutputContains($this->status($featureOrAgent, $isAgent), $needle);
    }

    /**
     * @param string $needle String that should be present in worktree list output
     */
    public function assertWorktreeListContains(string $needle): void
    {
        $this->assertOutputContains($this->runBacklog(['worktree-list']), $needle);
    }

    /**
     * @param string $agent Agent whose worktree to remove
     */
    public function removeManagedWorktree(string $agent): void
    {
        $path = $this->managedWorktreePath($agent);
        if (!$this->context->hasWorktree($path)) {
            throw new \RuntimeException(sprintf(
                'Refusing to remove unmanaged test worktree: %s',
                $this->relativePath($path),
            ));
        }

        if (!is_dir($path) && !is_file($path)) {
            return;
        }

        $this->runGitRoot(sprintf(
            'worktree remove --force %s',
            escapeshellarg($this->relativePath($path)),
        ));
    }

    /**
     * @param string $agent Agent whose worktree to restore
     */
    public function restoreWorktree(string $agent): void
    {
        $this->runBacklog(['worktree-restore', '--agent', $agent]);
        $path = $this->managedWorktreePath($agent);
        if (!is_dir($path) && !is_file($path)) {
            throw new \RuntimeException("Expected restored worktree not found: {$path}");
        }

        $this->context->recordWorktree($path);
    }

    /**
     * @param string $name File name for the body file
     * @param list<string> $lines Lines to write to the body file
     * @return string Path to the created body file
     */
    public function createBodyFile(string $name, array $lines): string
    {
        $path = $this->context->tmpDir . '/' . $name;
        $this->writeFile($path, implode("\n", $lines) . "\n");
        $this->context->recordTempFile($path);

        return $path;
    }

    /**
     * Creates a queued task using --body-file=<path>.
     *
     * @param list<string> $lines Lines to write to the temporary body file
     * @param string $name File name for the body file (kept under local/tmp/)
     */
    public function createTodoTaskFromBodyFile(array $lines, string $name): string
    {
        $path = $this->createBodyFile($name, $lines);
        $relative = $this->relativePath($path);
        $this->runBacklog(['task-create', '--body-file=' . $relative]);

        return $path;
    }

    /**
     * Asserts that the queued tasks of the test board contain the given block of lines, in order.
     *
     * @param list<string> $expectedLines
     */
    public function assertBoardTodoBlock(array $expectedLines): void
    {
        $contents = (string) file_get_contents($this->context->boardPath);
        $needle = implode("\n", $expectedLines);
        if (!str_contains($contents, $needle)) {
            throw new \RuntimeException(sprintf(
                "Expected backlog board to contain the queued block:\n%s\n--- actual board ---\n%s",
                $needle,
                $contents,
            ));
        }
    }

    /**
     * Asserts that the test board file does not contain the given text.
     */
    public function assertBoardLacksText(string $text): void
    {
        $contents = (string) file_get_contents($this->context->boardPath);
        if (str_contains($contents, $text)) {
            throw new \RuntimeException(sprintf(
                "Expected backlog board NOT to contain:\n%s\n--- actual board ---\n%s",
                $text,
                $contents,
            ));
        }
    }

    /**
     * Replaces one text fragment in the temporary backlog board.
     *
     * @param string $search Text fragment expected in the board
     * @param string $replace Replacement text fragment
     */
    public function replaceBoardText(string $search, string $replace): void
    {
        $contents = (string) file_get_contents($this->context->boardPath);
        if (!str_contains($contents, $search)) {
            throw new \RuntimeException(sprintf(
                "Expected backlog board to contain text before replacement:\n%s\n--- actual board ---\n%s",
                $search,
                $contents,
            ));
        }

        $this->writeFile($this->context->boardPath, str_replace($search, $replace, $contents));
    }

    /**
     * @param list<string> $arguments task-create arguments after the command name
     */
    public function assertTaskCreateFails(string $needle, array $arguments): void
    {
        $this->assertBacklogFails(array_merge(['task-create'], $arguments), $needle);
    }

    /**
     * Runs work-start with --dry-run and returns the captured output.
     *
     * @param list<string> $extraArguments Additional CLI arguments after --agent
     */
    public function dryRunStartNextFeature(string $agent, array $extraArguments = []): string
    {
        $arguments = array_merge(['work-start', '--dry-run'], $extraArguments);

        return $this->runBacklog($arguments, ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param list<string> $extraArguments Additional CLI arguments
     */
    public function assertWorkStartFails(string $agent, string $needle, array $extraArguments = []): void
    {
        $arguments = array_merge(['work-start'], $extraArguments);
        $this->assertBacklogFails($arguments, $needle, ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * Returns true when the agent has a managed worktree directory present on disk.
     */
    public function checkManagedWorktreeExists(string $agent): bool
    {
        return is_dir($this->managedWorktreePath($agent));
    }

    /**
     * @param list<string> $needles
     */
    public function assertOutputContainsAll(string $output, array $needles): void
    {
        foreach ($needles as $needle) {
            if (!str_contains($output, $needle)) {
                throw new \RuntimeException(sprintf(
                    "Expected command output to contain: %s\n--- actual output ---\n%s",
                    $needle,
                    $output,
                ));
            }
        }
    }

    /**
     * Runs two backlog commands in parallel and returns their [exitCode, output] pairs.
     *
     * Both processes are launched concurrently and collected after both finish.
     * Suitable for concurrency tests where serial execution would defeat the purpose.
     *
     * @param list<string> $argsA Arguments for the first command
     * @param list<string> $argsB Arguments for the second command
     * @param array<string, string> $envA Environment variables for the first command
     * @param array<string, string> $envB Environment variables for the second command
     * @return array{array{int, string}, array{int, string}} Results as [[codeA, outputA], [codeB, outputB]]
     */
    public function runTwoConcurrentBacklog(array $argsA, array $argsB, array $envA = [], array $envB = []): array
    {
        $cmdA = $this->buildBacklogCommand($argsA, $envA);
        $cmdB = $this->buildBacklogCommand($argsB, $envB);

        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $procA = proc_open($cmdA, $descriptors, $pipesA, $this->context->projectRoot);
        $procB = proc_open($cmdB, $descriptors, $pipesB, $this->context->projectRoot);

        if ($procA === false || $procB === false) {
            throw new \RuntimeException('Failed to start concurrent backlog processes.');
        }

        fclose($pipesA[0]);
        fclose($pipesB[0]);

        $outA = (string) stream_get_contents($pipesA[1]) . (string) stream_get_contents($pipesA[2]);
        fclose($pipesA[1]);
        fclose($pipesA[2]);

        $outB = (string) stream_get_contents($pipesB[1]) . (string) stream_get_contents($pipesB[2]);
        fclose($pipesB[1]);
        fclose($pipesB[2]);

        $codeA = proc_close($procA);
        $codeB = proc_close($procB);

        return [[$codeA, $outA], [$codeB, $outB]];
    }

    /**
     * @param list<string> $arguments Backlog command arguments
     * @param array<string, string> $env Environment variables
     * @return string Command output
     */
    public function runBacklog(array $arguments, array $env = []): string
    {
        $command = $this->buildBacklogCommand($arguments, $env);
        [$code, $output] = $this->consoleClient->captureWithExitCode($command);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Backlog command failed with exit code %d: %s\n%s",
                $code,
                $command,
                $output,
            ));
        }

        return $output;
    }

    /**
     * @param list<string> $arguments Backlog command arguments
     * @param string $needle Expected substring of the failure output
     * @param array<string, string> $env Optional environment variables
     */
    private function assertBacklogFails(array $arguments, string $needle, array $env = []): void
    {
        $command = $this->buildBacklogCommand($arguments, $env);
        [$code, $output] = $this->consoleClient->captureWithExitCode($command);
        if ($code === 0) {
            throw new \RuntimeException("Expected backlog command to fail: {$command}");
        }
        $this->assertOutputContains($output, $needle);
    }

    /**
     * @param list<string> $arguments Backlog command arguments
     * @param array<string, string> $env Environment variables
     */
    private function buildBacklogCommand(array $arguments, array $env = []): string
    {
        $parts = [];
        foreach ($env as $key => $value) {
            $parts[] = sprintf('%s=%s', $key, escapeshellarg($value));
        }

        $parts[] = 'php scripts/backlog.php';
        // Ensure the test always exercises the backlog code in the current worktree, never the proxied
        // copy in WP. WorktreeScriptProxy strips this flag from $argv before downstream argument parsing,
        // so it never reaches the backlog runner itself.
        $parts[] = '--force-current-worktree';
        foreach ($arguments as $argument) {
            $parts[] = escapeshellarg($argument);
        }

        $parts[] = '--test-mode';
        $parts[] = '--board-file';
        $parts[] = escapeshellarg($this->relativePath($this->context->boardPath));
        $parts[] = '--review-file';
        $parts[] = escapeshellarg($this->relativePath($this->context->reviewPath));
        $parts[] = '--worktree-dir';
        $parts[] = escapeshellarg($this->relativePath($this->context->worktreesRoot));
        if ($this->context->prBaseBranch() !== null) {
            $parts[] = '--pr-base-branch';
            $parts[] = escapeshellarg($this->context->prBaseBranch());
        }

        if ($this->context->dryRun) {
            $parts[] = '--dry-run';
        }
        if ($this->context->verbose) {
            $parts[] = '--verbose';
        }

        return implode(' ', $parts);
    }

    private function boardService(): BacklogBoardService
    {
        return new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
    }

    private function board(): BacklogBoard
    {
        return $this->boardService()->loadBoard($this->context->boardPath);
    }

    private function requireFeatureEntry(string $feature): \SoManAgent\Script\Backlog\Model\BoardEntry
    {
        $match = $this->boardService()->findParentFeatureEntry($this->board(), $feature);
        if ($match === null) {
            throw new \RuntimeException("Expected active feature not found in test backlog: {$feature}");
        }

        return $match->getEntry();
    }

    private function assertOutputContains(string $output, string $needle): void
    {
        if (!str_contains($output, $needle)) {
            throw new \RuntimeException("Expected command output to contain: {$needle}");
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("Unable to write test artifact: {$path}");
        }
    }

    private function runGitInWorktree(string $worktreePath, string $subCommand): void
    {
        $command = sprintf(
            'git -C %s %s',
            escapeshellarg($this->relativePath($worktreePath)),
            $subCommand,
        );
        [$code, $output] = $this->consoleClient->captureWithExitCode($command);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Git command failed with exit code %d: %s\n%s",
                $code,
                $command,
                $output,
            ));
        }
    }

    private function runGitRoot(string $subCommand): void
    {
        $command = sprintf('git %s', $subCommand);
        [$code, $output] = $this->consoleClient->captureWithExitCode($command);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Git command failed with exit code %d: %s\n%s",
                $code,
                $command,
                $output,
            ));
        }
    }

    private function cleanupTrackedResources(): void
    {
        if ($this->context->pullRequestNumber() !== null && !$this->context->isPullRequestMerged()) {
            $this->cleanupCommand(
                sprintf('close PR #%d', $this->context->pullRequestNumber()),
                sprintf('php scripts/github.php %s %d', GitHubCommandName::PR_CLOSE->value, $this->context->pullRequestNumber()),
                ['GitHub API error 404', 'already closed'],
            );
        }

        foreach ($this->context->remoteBranches() as $branch) {
            $this->cleanupCommand(
                sprintf('delete remote branch %s', $branch),
                sprintf('git push origin --delete %s', escapeshellarg($branch)),
                ['remote ref does not exist', 'unable to delete'],
            );
        }

        foreach ($this->context->worktrees() as $worktree) {
            if (!is_dir($worktree)) {
                $this->console->line(sprintf('[cleanup] already clean: %s', $this->relativePath($worktree)));
                continue;
            }

            $this->cleanupCommand(
                sprintf('remove worktree %s', $this->relativePath($worktree)),
                sprintf('git worktree remove --force %s', escapeshellarg($this->relativePath($worktree))),
                ['is not a working tree'],
            );
        }

        foreach ($this->context->localBranches() as $branch) {
            $this->cleanupCommand(
                sprintf('delete local branch %s', $branch),
                sprintf('git branch -D %s', escapeshellarg($branch)),
                ['not found', 'branch \''],
            );
        }

        if (!$this->context->keepArtifacts) {
            foreach ($this->context->tempFiles() as $path) {
                if (!is_file($path)) {
                    $this->console->line(sprintf('[cleanup] already clean: %s', $this->relativePath($path)));
                    continue;
                }

                if (!unlink($path)) {
                    $this->console->warn(sprintf('[cleanup] failed: remove temp file %s', $this->relativePath($path)));
                    continue;
                }

                $this->console->line(sprintf('[cleanup] cleaned: %s', $this->relativePath($path)));
            }
        }
    }

    /**
     * @param list<string> $expectedMissingNeedles
     */
    private function cleanupCommand(string $label, string $command, array $expectedMissingNeedles = []): void
    {
        [$code, $output] = $this->consoleClient->captureWithExitCode($command);
        if ($code === 0) {
            $this->console->line(sprintf('[cleanup] cleaned: %s', $label));

            return;
        }

        foreach ($expectedMissingNeedles as $needle) {
            if ($needle !== '' && str_contains($output, $needle)) {
                $this->console->line(sprintf('[cleanup] already clean: %s', $label));

                return;
            }
        }

        $this->console->warn(sprintf('[cleanup] failed: %s', $label));
        if ($output !== '') {
            $this->console->line($output);
        }
    }

    public function managedWorktreePath(string $agent): string
    {
        return $this->context->worktreesRoot . '/' . $agent;
    }

    private function relativePath(string $path): string
    {
        return $this->consoleClient->toRelativeProjectPath($path);
    }
}
