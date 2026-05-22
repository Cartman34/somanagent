<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog;

use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Storage\BoardYamlStorage;
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
    private const TEST_REVIEWER_AGENT = 'test-reviewer';

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
     * Reset test artifacts to clean state
     */
    public function resetArtifacts(): void
    {
        $this->writeFile($this->context->boardPath, BoardYamlStorage::initialContent());
        $this->removePath($this->context->migrationsDir);
        if (is_file($this->context->migrationMarkerPath) && !unlink($this->context->migrationMarkerPath)) {
            throw new \RuntimeException('Unable to remove test migration marker.');
        }

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
        $this->removePath($this->context->migrationsDir);
        if (is_file($this->context->migrationMarkerPath)) {
            unlink($this->context->migrationMarkerPath);
        }
    }

    /**
     * Run help checks: --help is the only accepted form; the old `help` command is gone.
     */
    public function runHelpChecks(): void
    {
        $this->assertOutputContains($this->runBacklog([]), 'Commands:');
        $this->assertOutputContains($this->runBacklog(['status', '--help']), 'status');
        $this->assertOutputContains($this->runBacklog([BacklogCommandName::REVIEW_NEXT->value, '--help']), BacklogCommandName::REVIEW_NEXT->value);
        $this->assertOutputContains($this->runBacklog([BacklogCommandName::START->value, '--help']), BacklogCommandName::START->value);
        $this->assertOutputContains($this->runBacklog([BacklogCommandName::MERGE->value, '--help']), BacklogCommandName::MERGE->value);
        $this->assertOutputContains($this->runBacklog([BacklogCommandName::REVIEW_REQUEST->value, '--help']), BacklogCommandName::REVIEW_REQUEST->value);
        $this->assertOutputContains($this->runBacklog([BacklogCommandName::REVIEW_CHECK->value, '--help']), BacklogCommandName::REVIEW_CHECK->value);
        $this->assertOutputContains($this->runBacklog([BacklogCommandName::REVIEW_APPROVE->value, '--help']), BacklogCommandName::REVIEW_APPROVE->value);
        $this->assertOutputContains($this->runBacklog([BacklogCommandName::REVIEW_REJECT->value, '--help']), BacklogCommandName::REVIEW_REJECT->value);
        $this->assertOutputContains($this->runBacklog([BacklogCommandName::REVIEW_AMEND->value, '--help']), BacklogCommandName::REVIEW_AMEND->value);
        $this->assertOutputContains($this->runBacklog([BacklogCommandName::USER_MERGE->value, '--help']), BacklogCommandName::USER_MERGE->value);
        // Regression: `help` and `help <command>` must be unknown commands, not silent aliases.
        $this->assertCommandIsUnknown('help');
        $this->assertBacklogFails(
            ['help', BacklogCommandName::START->value],
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
            $this->runBacklog(['--force-current-worktree', BacklogCommandName::START->value, '--help']),
            BacklogCommandName::START->value,
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
            [BacklogCommandName::REVIEW_REJECT->value, 'missing-feature/missing-task', '--body-file=' . $bodyFile],
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
            [BacklogCommandName::START->value, '--unknown-flag'],
            'Unknown option(s) for command `start`: --unknown-flag',
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
            [BacklogCommandName::REVIEW_CHECK->value, '--as=' . $this->context->agentPrimary],
            'Unknown option(s) for command `review-check`: --as',
        );
    }

    /**
     * Creates a queued task from inline bracket-prefix text by parsing it and calling the new CLI.
     *
     * Supports the legacy `[type][feature][task] title` bracket syntax internally; the CLI itself
     * receives structured --feature / --task / --type / --body-file options.
     *
     * @param string $text Task text (single-line or multi-line with embedded newlines)
     */
    public function createTodoTask(string $text): void
    {
        $service = $this->boardService();
        $lines = preg_split('/\R/', $text) ?: [$text];
        $firstLine = rtrim($lines[0]);

        if (preg_match('/^-\s+(.*)$/', $firstLine, $m) === 1) {
            $firstLine = $m[1];
        }

        [$taskType, $cleaned] = $service->extractTypePrefix($firstLine);
        $type = $taskType?->value;

        $scoped = $service->extractScopedTaskMetadata($cleaned);
        if ($scoped !== null) {
            $feature = $scoped['featureGroup'];
            $task = $scoped['task'];
            $titleLine = $scoped['text'];
        } else {
            $single = $service->extractSingleFeaturePrefixMetadata($cleaned);
            if ($single !== null) {
                $feature = $single['featureSlug'];
                $task = null;
                $titleLine = $single['text'];
            } else {
                $feature = $service->normalizeFeatureSlug($cleaned);
                $task = null;
                $titleLine = $cleaned;
            }
        }

        // --type is now mandatory on the CLI; default test fixtures to 'feat' when the legacy
        // bracket prefix didn't carry an explicit type. Production callers must pass --type=<…>.
        $type ??= 'feat';

        $bodyLines = array_slice($lines, 1);
        $name = 'entry-create-' . substr(md5($text), 0, 8) . '.md';
        $this->createTodoTaskFromBodyFile($feature, $titleLine, $bodyLines, $task, $type, $name);
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
        $this->runBacklog([BacklogCommandName::ENTRY_REMOVE->value, $reference]);
    }

    /**
     * Remove a queued todo task by its stable reference.
     *
     * @param string $reference <entry-ref>
     */
    public function removeTodoTask(string $reference): void
    {
        $this->runBacklog([BacklogCommandName::ENTRY_REMOVE->value, $reference]);
    }

    /**
     * Asserts that `entry-remove` fails with the given message.
     *
     * @param string $reference Reference passed to entry-remove
     * @param string $needle Expected substring of the failure output
     */
    public function assertTaskRemoveFails(string $reference, string $needle): void
    {
        $this->assertBacklogFails([BacklogCommandName::ENTRY_REMOVE->value, $reference], $needle);
    }

    /**
     * @param string $needle String that should be present in todo list output
     */
    public function assertTodoContains(string $needle): void
    {
        $this->assertOutputContains($this->runBacklog(['list', '--stage=todo']), $needle);
    }

    /**
     * @param string $agent Agent identifier for the worktree
     * @param ?string $target Optional explicit `<entry-ref>`
     * @return string Command output from start
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

        $args = [BacklogCommandName::START->value];
        if ($target !== null) {
            $args[] = $target;
        }
        $output = $this->runBacklog($args, ['SOMANAGER_AGENT' => $agent]);
        $this->context->recordWorktree($worktreePath);

        return $output;
    }

    /**
     * Runs `list --stage=review` and returns its output.
     */
    public function reviewList(): string
    {
        return $this->runBacklog(['list', '--stage=review', '--flat']);
    }

    /**
     * @param string $output start output to check
     * @param string $needle String that should be present in output
     */
    public function assertFeatureStartOutputContains(string $output, string $needle): void
    {
        $this->assertOutputContains($output, $needle);
    }

    /**
     * @param string $reference  Entry reference (`<entry-ref>`) to assign
     * @param string $agent      Developer code to assign the entry to
     */
    public function assignEntryAsManager(string $reference, string $agent): void
    {
        $this->runBacklog([BacklogCommandName::ASSIGN->value, $reference, '--agent', $agent], ['SOMANAGER_ROLE' => 'manager']);
    }

    /**
     * Asserts that `assign` fails with the given message.
     *
     * @param string $reference  Entry reference (`<entry-ref>`) to assign
     * @param string $agent      Developer code passed via `--developer`
     * @param array<string, string> $env Environment variables (typically SOMANAGER_ROLE / SOMANAGER_AGENT)
     * @param string $needle     Expected substring of the failure output
     */
    public function assertAssignEntryFails(string $reference, string $agent, array $env, string $needle): void
    {
        $this->assertBacklogFails([BacklogCommandName::ASSIGN->value, $reference, '--agent', $agent], $needle, $env);
    }

    /**
     * Unassigns an entry (feature or task) using the manager role.
     *
     * @param ?string $reference Optional explicit reference (`<entry-ref>`).
     *                           When null, the script falls back to the agent's single active entry.
     * @param string $developer Caller developer code passed via `--developer`.
     */
    public function unassignEntryAsManager(?string $reference, string $developer): void
    {
        $arguments = [BacklogCommandName::UNASSIGN->value];
        if ($reference !== null) {
            $arguments[] = $reference;
        }
        $arguments[] = '--developer';
        $arguments[] = $developer;
        $this->runBacklog($arguments, ['SOMANAGER_ROLE' => 'manager']);
    }

    /**
     * Asserts that `unassign` fails with the given message when invoked under the
     * provided role/agent environment.
     *
     * @param ?string $reference Optional explicit reference (`<entry-ref>`).
     * @param string $developer Caller developer code passed via `--developer`.
     * @param array<string, string> $env Environment variables (typically SOMANAGER_ROLE / SOMANAGER_AGENT).
     * @param string $needle Expected substring of the failure output.
     */
    public function assertUnassignEntryFails(?string $reference, string $developer, array $env, string $needle): void
    {
        $arguments = [BacklogCommandName::UNASSIGN->value];
        if ($reference !== null) {
            $arguments[] = $reference;
        }
        $arguments[] = '--developer';
        $arguments[] = $developer;
        $this->assertBacklogFails($arguments, $needle, $env);
    }

    /**
     * @param string $agent      Agent releasing the entry
     * @param string $entryRef   Entry ref to release (feature slug or feature/task)
     */
    public function releaseEntry(string $agent, string $entryRef): void
    {
        $this->runBacklog([BacklogCommandName::RELEASE->value, $entryRef], ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param string $manager Agent code used as manager caller
     * @param string $entryRef Entry ref to release
     */
    public function releaseEntryAsManager(string $manager, string $entryRef): void
    {
        $this->runBacklog([BacklogCommandName::RELEASE->value, $entryRef], [
            'SOMANAGER_ROLE' => 'manager',
            'SOMANAGER_AGENT' => $manager,
        ]);
    }

    /**
     * @param string $agent      Agent attempting the release
     * @param string $entryRef   Entry ref to release
     * @param string $needle     Expected error message
     */
    public function assertReleaseEntryFails(string $agent, string $entryRef, string $needle): void
    {
        $this->assertBacklogFails([BacklogCommandName::RELEASE->value, $entryRef], $needle, ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param string $manager Agent code used as manager caller
     * @param string $needle Expected error message
     */
    public function assertReleaseEntryAsManagerWithoutReferenceFails(string $manager, string $needle): void
    {
        $this->assertBacklogFails([BacklogCommandName::RELEASE->value], $needle, [
            'SOMANAGER_ROLE' => 'manager',
            'SOMANAGER_AGENT' => $manager,
        ]);
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
        $this->runBacklog([BacklogCommandName::REVIEW_REQUEST->value], ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param string $reviewer Reviewer agent code (required by review-next)
     * @param ?string $target Optional explicit `<entry-ref>`
     * @return string Output from review-next command
     */
    public function reviewNext(string $reviewer, ?string $target = null): string
    {
        $args = [BacklogCommandName::REVIEW_NEXT->value];
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
        $args = [BacklogCommandName::REVIEW_NEXT->value];
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
        $args = [BacklogCommandName::REVIEW_CANCEL->value];
        if ($reference !== '') {
            $args[] = $reference;
        }
        $this->runBacklog($args, ['SOMANAGER_AGENT' => $reviewer]);
    }

    /**
     * @param string $manager Manager agent code
     * @param string $reference Entry reference
     */
    public function reviewCancelAsManager(string $manager, string $reference): void
    {
        $this->runBacklog([BacklogCommandName::REVIEW_CANCEL->value, $reference], [
            'SOMANAGER_ROLE' => 'manager',
            'SOMANAGER_AGENT' => $manager,
        ]);
    }

    /**
     * @param string $reviewer Reviewer agent code
     * @param string $reference Optional entry reference; empty to omit
     * @param string $needle Expected error message
     */
    public function assertReviewCancelFails(string $reviewer, string $reference, string $needle): void
    {
        $args = [BacklogCommandName::REVIEW_CANCEL->value];
        if ($reference !== '') {
            $args[] = $reference;
        }
        $this->assertBacklogFails($args, $needle, ['SOMANAGER_AGENT' => $reviewer]);
    }

    /**
     * @param string $manager Manager agent code
     * @param string $needle Expected error message
     */
    public function assertReviewCancelAsManagerWithoutReferenceFails(string $manager, string $needle): void
    {
        $this->assertBacklogFails([BacklogCommandName::REVIEW_CANCEL->value], $needle, [
            'SOMANAGER_ROLE' => 'manager',
            'SOMANAGER_AGENT' => $manager,
        ]);
    }

    /**
     * @param string $reviewer Reviewer agent code
     * @param string $reference <entry-ref>
     * @return string Command output
     */
    public function reviewCheck(string $reviewer, string $reference): string
    {
        return $this->runBacklog([BacklogCommandName::REVIEW_CHECK->value, $reference], ['SOMANAGER_AGENT' => $reviewer]);
    }

    /**
     * @param string $reviewer Reviewer agent code
     * @param string $reference <entry-ref>
     * @param string $needle Expected error message
     */
    public function assertReviewCheckFails(string $reviewer, string $reference, string $needle): void
    {
        $this->assertBacklogFails([BacklogCommandName::REVIEW_CHECK->value, $reference], $needle, ['SOMANAGER_AGENT' => $reviewer]);
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
        $this->runBacklog([BacklogCommandName::REVIEW_APPROVE->value, $feature, '--body-file', $bodyFile], ['SOMANAGER_AGENT' => $reviewer]);
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
        $this->runBacklog([BacklogCommandName::REVIEW_APPROVE->value, $reference], ['SOMANAGER_AGENT' => $reviewer]);
    }

    /**
     * @param string $reviewer Reviewer agent code
     * @param string $reference Feature slug or task reference
     * @param string|null $bodyFile Path to body file (null to omit)
     * @param string $needle Expected error message
     */
    public function assertReviewApproveFails(string $reviewer, string $reference, ?string $bodyFile, string $needle): void
    {
        $args = [BacklogCommandName::REVIEW_APPROVE->value, $reference];
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
        $this->runBacklog([BacklogCommandName::REVIEW_REJECT->value, $reference, '--body-file', $bodyFile], ['SOMANAGER_AGENT' => $reviewer]);
    }

    /**
     * @param string $reviewer Reviewer agent code
     * @param string $reference Feature slug or task reference
     * @param string|null $bodyFile Path to body file (null to omit --body-file)
     * @param string $needle Expected error message
     */
    public function assertReviewRejectFails(string $reviewer, string $reference, ?string $bodyFile, string $needle): void
    {
        $args = [BacklogCommandName::REVIEW_REJECT->value, $reference];
        if ($bodyFile !== null) {
            $args[] = '--body-file';
            $args[] = $bodyFile;
        }
        $this->assertBacklogFails($args, $needle, ['SOMANAGER_AGENT' => $reviewer]);
    }

    /**
     * Amend review notes on a rejected feature or task via review-amend.
     *
     * @param string $reviewer Reviewer agent code
     * @param string $reference <entry-ref>
     * @param string $bodyFile Path to body file with replacement findings
     */
    public function reviewAmend(string $reviewer, string $reference, string $bodyFile): void
    {
        $this->runBacklog(
            [BacklogCommandName::REVIEW_AMEND->value, $reference, '--body-file', $bodyFile],
            ['SOMANAGER_ROLE' => 'reviewer', 'SOMANAGER_AGENT' => $reviewer],
        );
    }

    /**
     * @param string $reviewer Reviewer agent code
     * @param string $reference Feature slug or task reference
     * @param string|null $bodyFile Path to body file (null to omit --body-file)
     * @param string $needle Expected error message
     * @param array<string, string> $extraEnv Additional env vars (e.g. override SOMANAGER_ROLE)
     */
    public function assertReviewAmendFails(string $reviewer, string $reference, ?string $bodyFile, string $needle, array $extraEnv = []): void
    {
        $args = [BacklogCommandName::REVIEW_AMEND->value, $reference];
        if ($bodyFile !== null) {
            $args[] = '--body-file';
            $args[] = $bodyFile;
        }
        $env = array_merge(['SOMANAGER_ROLE' => 'reviewer', 'SOMANAGER_AGENT' => $reviewer], $extraEnv);
        $this->assertBacklogFails($args, $needle, $env);
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

    /**
     * Reopen an approved entry for a new review cycle.
     *
     * @param string $role Caller role: 'manager' or 'reviewer'
     * @param string $agent Caller agent code
     * @param string $reference Mandatory stable reference (<entry-ref>)
     */
    public function reviewReopen(string $role, string $agent, string $reference): void
    {
        $this->runBacklog([BacklogCommandName::REVIEW_REOPEN->value, $reference], [
            'SOMANAGER_ROLE' => $role,
            'SOMANAGER_AGENT' => $agent,
        ]);
    }

    /**
     * Injects review notes directly into the review file, bypassing backlog.php.
     *
     * Use this only to set up note-clearing tests: notes are normally cleared by
     * `review-approve`, so a direct injection is the only way to place notes in the
     * file while an entry is already in `approved` state.
     *
     * @param string $key Review key: feature slug or feature/task composite reference
     * @param list<string> $lines Note lines to inject
     */
    public function injectReviewNote(string $key, array $lines): void
    {
        $service = $this->boardService();
        $review = $service->loadReviewFile($this->context->reviewPath);
        $review->setReview($key, $lines);
        $service->saveReviewFile($review);
    }

    /**
     * Asserts that review-reopen fails with the expected error message.
     *
     * @param string $role Caller role: 'manager' or 'reviewer'
     * @param string $agent Caller agent code
     * @param string $reference Reference passed to review-reopen (empty to omit)
     * @param string $needle Expected substring of the failure output
     */
    public function assertReviewReopenFails(string $role, string $agent, string $reference, string $needle): void
    {
        $args = [BacklogCommandName::REVIEW_REOPEN->value];
        if ($reference !== '') {
            $args[] = $reference;
        }
        $this->assertBacklogFails($args, $needle, [
            'SOMANAGER_ROLE' => $role,
            'SOMANAGER_AGENT' => $agent,
        ]);
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
     * Assert the active feature entry has the expected stage value.
     *
     * @param string $feature Feature slug
     * @param string $expectedStage One of BacklogBoard::STAGE_* constants
     */
    public function assertFeatureStage(string $feature, string $expectedStage): void
    {
        $service = $this->boardService();
        $match = $service->findParentFeatureEntry($this->board(), $feature);
        if ($match === null) {
            throw new \RuntimeException(sprintf('Expected active feature not found: %s', $feature));
        }
        $actual = $service->getFeatureStage($match->getEntry());
        if ($actual !== $expectedStage) {
            throw new \RuntimeException(sprintf(
                'Expected feature %s to be in stage %s, got %s.',
                $feature,
                $expectedStage,
                $actual,
            ));
        }
    }

    /**
     * Assert the active feature entry has no reviewer metadata set.
     *
     * @param string $feature Feature slug
     */
    public function assertFeatureReviewerCleared(string $feature): void
    {
        $match = $this->boardService()->findParentFeatureEntry($this->board(), $feature);
        if ($match === null) {
            throw new \RuntimeException(sprintf('Expected active feature not found: %s', $feature));
        }
        $reviewer = $match->getEntry()->getReviewer();
        if ($reviewer !== null) {
            throw new \RuntimeException(sprintf(
                'Expected feature %s to have no reviewer, got %s.',
                $feature,
                $reviewer,
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
        $args = [BacklogCommandName::REVIEW_NOTES->value];
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
        $args = [BacklogCommandName::REVIEW_NOTES->value];
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
    public function mergeTask(string $reference, ?string $expectedReviewer = null, ?string $expectedCaller = null): void
    {
        $output = $this->runBacklog([BacklogCommandName::MERGE->value, $reference], ['SOMANAGER_AGENT' => self::TEST_REVIEWER_AGENT]);
        $this->assertOutputContains($output, 'Entry-ref: ' . $reference);
        if ($expectedReviewer !== null) {
            $this->assertOutputContains($output, 'Reviewer: ' . $expectedReviewer);
        }
        if ($expectedCaller !== null) {
            $this->assertOutputContains($output, 'Caller: ' . $expectedCaller);
        }
        $this->assertOutputContains($output, 'Resolved type: task');
        $this->assertOutputContains($output, 'Equivalent command: feature-task-merge ' . $reference);
    }

    /**
     * @param string $manager Manager agent code
     * @param string $reference Task reference to merge
     * @param string $expectedReviewer Stored reviewer expected in the output
     */
    public function mergeTaskAsManager(string $manager, string $reference, string $expectedReviewer): void
    {
        $output = $this->runBacklog([BacklogCommandName::MERGE->value, $reference], [
            'SOMANAGER_ROLE' => 'manager',
            'SOMANAGER_AGENT' => $manager,
        ]);
        $this->assertOutputContains($output, 'Entry-ref: ' . $reference);
        $this->assertOutputContains($output, 'Reviewer: ' . $expectedReviewer);
        $this->assertOutputContains($output, 'Caller: ' . $manager . ' (manager)');
        $this->assertOutputContains($output, 'Resolved type: task');
    }

    /**
     * Assert merge refuses to infer a task from the developer agent option.
     *
     * @param string $agent Developer agent that owns a task
     */
    public function assertEntryMergeWithoutReferenceFails(string $agent): void
    {
        $this->assertBacklogFails(
            [BacklogCommandName::MERGE->value],
            'merge requires <entry-ref>.',
            ['SOMANAGER_AGENT' => $agent],
        );
    }

    /**
     * @param string $reference Feature or task reference
     */
    public function assertEntryMergeRequiresReviewer(string $reference): void
    {
        $this->assertBacklogFails(
            [BacklogCommandName::MERGE->value, $reference],
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
            [BacklogCommandName::MERGE->value, $task],
            'merge refuses short task reference',
            ['SOMANAGER_AGENT' => self::TEST_REVIEWER_AGENT],
        );
    }

    /**
     * @param string $reference Full task reference
     * @param string $bodyFile Body file that should be rejected on task merges
     */
    public function assertEntryMergeTaskBodyFileFails(string $reference, string $bodyFile): void
    {
        $this->assertBacklogFails(
            [BacklogCommandName::MERGE->value, $reference, '--body-file', $bodyFile],
            'merge accepts --body-file only for feature merges.',
            ['SOMANAGER_AGENT' => self::TEST_REVIEWER_AGENT],
        );
    }

    /**
     * Runs migrate.php --generate with the given agent code.
     *
     * Skipped in dry-run mode and when --allow-integration is not set (requires a live local
     * PostgreSQL on localhost:5432). Asserts that the command runs locally (no Docker), that the
     * temp DB name is derived from the agent code, that DATABASE_URL targets localhost:5432, and
     * that board meta is cleared after the command completes.
     *
     * @param string $agent Agent code used to name the temporary database
     */
    public function runMigrateGenerate(string $agent): void
    {
        if ($this->context->dryRun) {
            return;
        }

        if (!$this->context->allowIntegration) {
            $this->console->warn('[migrate --generate] Skipped: --allow-integration not set (requires a live local PostgreSQL on localhost:5432).');

            return;
        }

        $expectedDbName = preg_replace('/[^a-z0-9]/', '_', strtolower($agent)) . '_migrate_gen';

        $command = sprintf(
            'SOMANAGER_ROLE=developer SOMANAGER_AGENT=%s php scripts/migrate.php --generate',
            escapeshellarg($agent),
        );
        [$code, $output] = $this->consoleClient->captureWithExitCode($command);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "migrate.php --generate failed (exit %d):\n%s",
                $code,
                $output,
            ));
        }

        if (!str_contains($output, $expectedDbName)) {
            throw new \RuntimeException(sprintf(
                "migrate.php --generate output does not mention expected temp DB name '%s':\n%s",
                $expectedDbName,
                $output,
            ));
        }

        if (!str_contains($output, 'localhost:5432')) {
            throw new \RuntimeException(sprintf(
                "migrate.php --generate output does not mention 'localhost:5432' — command may not be running locally:\n%s",
                $output,
            ));
        }

        $this->assertBoardLacksText('    database:');
    }

    /**
     * @param string $agent Agent whose active entry is renamed
     * @param string $newText New entry text
     */
    public function renameEntry(string $agent, string $newText): void
    {
        $this->runBacklog([BacklogCommandName::RENAME->value, $newText], ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param string $manager Manager agent code
     * @param string $entryRef Entry reference to rename
     * @param string $newText New entry text
     */
    public function renameEntryAsManager(string $manager, string $entryRef, string $newText): void
    {
        $this->runBacklog([BacklogCommandName::RENAME->value, $entryRef, $newText], [
            'SOMANAGER_ROLE' => 'manager',
            'SOMANAGER_AGENT' => $manager,
        ]);
    }

    /**
     * @param string $manager Manager agent code
     * @param string $needle Expected error message
     */
    public function assertRenameEntryAsManagerWithoutReferenceFails(string $manager, string $needle): void
    {
        $this->assertBacklogFails([BacklogCommandName::RENAME->value], $needle, [
            'SOMANAGER_ROLE' => 'manager',
            'SOMANAGER_AGENT' => $manager,
        ]);
    }

    /**
     * @param string $agent Caller agent
     * @param string $entryRef Explicit entry reference
     * @param string $newText New entry text
     * @param string $needle Expected error message
     */
    public function assertRenameEntryFails(string $agent, string $entryRef, string $newText, string $needle): void
    {
        $this->assertBacklogFails([BacklogCommandName::RENAME->value, $entryRef, $newText], $needle, ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * Sets or clears an extra-metadata key on the given active entry.
     *
     * @param string $agent Agent code used as SOMANAGER_AGENT
     * @param string $entryRef Feature slug or feature/task reference identifying the active entry
     * @param string $assignment Key=value assignment, e.g. "database=my_db" or "database=" to clear
     */
    public function setEntryMeta(string $agent, string $entryRef, string $assignment): void
    {
        $this->runBacklog([BacklogCommandName::ENTRY_SET_META->value, $entryRef, $assignment], ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * Asserts that entry-set-meta fails and that the output contains the expected needle.
     *
     * @param string $agent Agent code used as SOMANAGER_AGENT
     * @param string $entryRef Feature slug or feature/task reference passed to the command
     * @param string $assignment Key=value assignment passed to the command
     * @param string $needle Expected substring of the failure output
     */
    public function assertSetEntryMetaFails(string $agent, string $entryRef, string $assignment, string $needle): void
    {
        $this->assertBacklogFails([BacklogCommandName::ENTRY_SET_META->value, $entryRef, $assignment], $needle, ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * Asserts that the backlog board file contains the given text fragment.
     *
     * @param string $needle Text expected anywhere in the board file
     */
    public function assertBoardContains(string $needle): void
    {
        $contents = (string) file_get_contents($this->context->boardPath);
        if (!str_contains($contents, $needle)) {
            throw new \RuntimeException(sprintf(
                "Expected backlog board to contain:\n%s\n--- actual board ---\n%s",
                $needle,
                $contents,
            ));
        }
    }

    /**
     * Returns the raw contents of the backlog board file.
     *
     * Used by ordering assertions that need to compare substring positions
     * across multiple entries within the YAML output.
     */
    public function getBoardText(): string
    {
        return (string) file_get_contents($this->context->boardPath);
    }

    /**
     * Overwrites the backlog board file with the given text.
     *
     * Use this to restore a previously saved board snapshot, e.g. to simulate a crash
     * that left the board in an earlier state before a retry.
     *
     * @param string $text Full board YAML content to write
     */
    public function setBoardText(string $text): void
    {
        $this->writeFile($this->context->boardPath, $text);
    }

    /**
     * @param string $agent Agent requesting the review
     */
    public function requestFeatureReview(string $agent): void
    {
        $this->runBacklog([BacklogCommandName::REVIEW_REQUEST->value], ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param string $agent Agent blocking the feature
     * @param string $feature Feature name to block
     */
    public function blockFeature(string $agent, string $feature): void
    {
        $this->runBacklog([BacklogCommandName::FEATURE_BLOCK->value, $feature], ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param string $manager Manager agent code
     * @param string $feature Feature slug
     */
    public function blockFeatureAsManager(string $manager, string $feature): void
    {
        $this->runBacklog([BacklogCommandName::FEATURE_BLOCK->value, $feature], [
            'SOMANAGER_ROLE' => 'manager',
            'SOMANAGER_AGENT' => $manager,
        ]);
    }

    /**
     * @param string $manager Manager agent code
     * @param string $needle Expected error message
     */
    public function assertBlockFeatureAsManagerWithoutReferenceFails(string $manager, string $needle): void
    {
        $this->assertBacklogFails([BacklogCommandName::FEATURE_BLOCK->value], $needle, [
            'SOMANAGER_ROLE' => 'manager',
            'SOMANAGER_AGENT' => $manager,
        ]);
    }

    /**
     * @param string $agent Caller agent
     * @param string $feature Feature slug
     * @param string $needle Expected error message
     */
    public function assertBlockFeatureFails(string $agent, string $feature, string $needle): void
    {
        $this->assertBacklogFails([BacklogCommandName::FEATURE_BLOCK->value, $feature], $needle, ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param string $agent Agent unblocking the feature
     * @param string $feature Feature name to unblock
     */
    public function unblockFeature(string $agent, string $feature): void
    {
        $this->runBacklog([BacklogCommandName::FEATURE_UNBLOCK->value, $feature], ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param string $manager Manager agent code
     * @param string $feature Feature slug
     */
    public function unblockFeatureAsManager(string $manager, string $feature): void
    {
        $this->runBacklog([BacklogCommandName::FEATURE_UNBLOCK->value, $feature], [
            'SOMANAGER_ROLE' => 'manager',
            'SOMANAGER_AGENT' => $manager,
        ]);
    }

    /**
     * @param string $manager Manager agent code
     * @param string $needle Expected error message
     */
    public function assertUnblockFeatureAsManagerWithoutReferenceFails(string $manager, string $needle): void
    {
        $this->assertBacklogFails([BacklogCommandName::FEATURE_UNBLOCK->value], $needle, [
            'SOMANAGER_ROLE' => 'manager',
            'SOMANAGER_AGENT' => $manager,
        ]);
    }

    /**
     * @param string $agent Caller agent
     * @param string $feature Feature slug
     * @param string $needle Expected error message
     */
    public function assertUnblockFeatureFails(string $agent, string $feature, string $needle): void
    {
        $this->assertBacklogFails([BacklogCommandName::FEATURE_UNBLOCK->value, $feature], $needle, ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param string $feature Feature name to merge
     * @param string|null $bodyFile Path to merge body file
     */
    public function mergeFeature(string $feature, ?string $bodyFile = null): void
    {
        $arguments = [BacklogCommandName::MERGE->value, $feature];
        if ($bodyFile !== null) {
            $arguments[] = '--body-file';
            $arguments[] = $bodyFile;
        }

        $output = $this->runBacklog($arguments, ['SOMANAGER_AGENT' => self::TEST_REVIEWER_AGENT]);
        $this->assertOutputContains($output, 'Entry-ref: ' . $feature);
        $this->assertOutputContains($output, 'Resolved type: feature');
        $this->assertOutputContains($output, 'Equivalent command: feature-merge ' . $feature);
        $this->context->markPullRequestMerged();
    }

    /**
     * @param string $agent Agent committing the change
     * @param string $feature Feature name
     * @param string $fileName Name of the file to create
     */
    public function commitFeatureChange(string $agent, string $feature, string $fileName): void
    {
        if (str_contains($fileName, '/')) {
            throw new \InvalidArgumentException("commitFeatureChange: filename must be a bare basename, not a path (got: {$fileName})");
        }

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
        $this->runBacklog(['worktree-restore', '--developer', $agent]);
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
     * Creates a queued task using the structured entry-create CLI options.
     *
     * The body file receives the title on the first line followed by any body lines.
     *
     * @param string $feature Feature slug
     * @param string $titleLine Entry title (first line of body file)
     * @param list<string> $bodyLines Additional body/sub-task lines
     * @param string|null $task Task slug for scoped child tasks
     * @param string|null $type Branch type: feat, fix, or tech. When null, defaults to 'feat' since
     *                          --type is now mandatory on the CLI.
     * @param string $name File name for the temporary body file
     */
    public function createTodoTaskFromBodyFile(
        string $feature,
        string $titleLine,
        array $bodyLines,
        ?string $task,
        ?string $type,
        string $name,
    ): string {
        $allLines = array_merge([$titleLine], $bodyLines);
        $path = $this->createBodyFile($name, $allLines);
        $relative = $this->relativePath($path);

        $args = [BacklogCommandName::ENTRY_CREATE->value, '--feature=' . $feature, '--type=' . ($type ?? 'feat'), '--body-file=' . $relative];
        if ($task !== null) {
            $args[] = '--task=' . $task;
        }
        $this->runBacklog($args);

        return $path;
    }

    /**
     * Renames a todo entry by replacing its feature slug (and optionally its title) in the YAML board.
     *
     * Uses replaceBoardText so the exact YAML substring must be unique in the file.
     *
     * @param string $oldFeature  Current feature slug in the board
     * @param string $newFeature  Replacement feature slug
     * @param string $oldTitle    Current title (used to disambiguate when multiple entries share the same feature slug)
     * @param string $newTitle    Replacement title
     */
    public function renameTodoEntry(string $oldFeature, string $newFeature, string $oldTitle, string $newTitle): void
    {
        $oldDump = \Symfony\Component\Yaml\Yaml::dump($oldTitle);
        $newDump = \Symfony\Component\Yaml\Yaml::dump($newTitle);
        $contents = (string) file_get_contents($this->context->boardPath);
        // Match `feature: <old>\n    <intermediate lines like type:/task:/agent:>\n    title: <old>` (non-greedy)
        // so the rename works regardless of which optional fields sit between feature and title.
        $pattern = '/feature: ' . preg_quote($oldFeature, '/')
            . '((?:\n    [^\n]+)*?)\n    title: ' . preg_quote($oldDump, '/') . '/';
        $replacement = 'feature: ' . $newFeature . '$1' . "\n    title: " . $newDump;
        $replaced = preg_replace($pattern, $replacement, $contents, 1, $count);
        if (!is_string($replaced) || $count === 0) {
            throw new \RuntimeException(sprintf(
                "Expected backlog board to contain a todo entry feature=%s title=%s.\n--- actual board ---\n%s",
                $oldFeature,
                $oldTitle,
                $contents,
            ));
        }
        $this->writeFile($this->context->boardPath, $replaced);
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
     * @param list<string> $arguments entry-create arguments after the command name
     */
    public function assertTaskCreateFails(string $needle, array $arguments): void
    {
        $this->assertBacklogFails(array_merge([BacklogCommandName::ENTRY_CREATE->value], $arguments), $needle);
    }

    /**
     * Runs start with --dry-run and returns the captured output.
     *
     * @param list<string> $extraArguments Additional CLI arguments after --agent
     */
    public function dryRunStartNextFeature(string $agent, array $extraArguments = []): string
    {
        $arguments = array_merge([BacklogCommandName::START->value, '--dry-run'], $extraArguments);

        return $this->runBacklog($arguments, ['SOMANAGER_AGENT' => $agent]);
    }

    /**
     * @param list<string> $extraArguments Additional CLI arguments
     */
    public function assertWorkStartFails(string $agent, string $needle, array $extraArguments = []): void
    {
        $arguments = array_merge([BacklogCommandName::START->value], $extraArguments);
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
     * Asserts that the agent's managed worktree directory exists on disk.
     *
     * @param string $agent Agent code
     */
    public function assertManagedWorktreeExists(string $agent): void
    {
        if (!is_dir($this->managedWorktreePath($agent))) {
            throw new \RuntimeException(sprintf(
                'Expected developer worktree to exist on disk for agent %s: %s',
                $agent,
                $this->relativePath($this->managedWorktreePath($agent)),
            ));
        }
    }

    /**
     * Asserts that the agent's managed worktree directory no longer exists on disk.
     *
     * @param string $agent Agent code
     */
    public function assertManagedWorktreeGone(string $agent): void
    {
        if (is_dir($this->managedWorktreePath($agent))) {
            throw new \RuntimeException(sprintf(
                'Expected developer worktree to be deleted after merge, but still exists: %s',
                $this->relativePath($this->managedWorktreePath($agent)),
            ));
        }
    }

    /**
     * Asserts that the given local branch no longer exists in the git repository.
     *
     * @param string $branch Local branch name
     */
    public function assertLocalBranchGone(string $branch): void
    {
        [, $output] = $this->consoleClient->captureWithExitCode(
            sprintf('git branch --list %s', escapeshellarg($branch)),
        );
        if (trim($output) !== '') {
            throw new \RuntimeException(sprintf(
                'Expected local branch %s to be deleted after merge, but it still exists.',
                $branch,
            ));
        }
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
     * Runs a backlog command with piped stdin for interactive testing.
     *
     * The global --dry-run flag is intentionally NOT added, even when context->dryRun is true:
     * interactive tests require the command to prompt, which --dry-run suppresses.
     * Guard with `if (!$context->dryRun)` in the campaign for tests that cause mutations.
     *
     * @param list<string> $arguments Backlog command arguments
     * @param string $stdinInput Characters to pipe to stdin (e.g. "y\n", "n\n", "d\ny\n")
     * @param array<string, string> $env Extra environment variables (e.g. BACKLOG_TEST_FORCE_INTERACTIVE=1)
     * @return array{int, string} [exitCode, combinedOutput]
     */
    public function runBacklogWithPipedStdin(array $arguments, string $stdinInput, array $env = []): array
    {
        $command = $this->buildInteractiveBacklogCommand($arguments, $env);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $this->context->projectRoot);
        if ($process === false) {
            throw new \RuntimeException('Failed to launch interactive backlog process.');
        }
        fwrite($pipes[0], $stdinInput);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, $output];
    }

    /**
     * Asserts that user-merge fails (exit non-zero) when given the provided stdin input.
     *
     * @param string $stdinInput Characters piped to stdin
     * @param string $needle Expected substring in the failure output
     * @param array<string, string> $env Extra environment variables
     */
    public function assertUserMergeWithPipedStdinFails(string $stdinInput, string $needle, array $env = []): void
    {
        [$exitCode, $output] = $this->runBacklogWithPipedStdin([BacklogCommandName::USER_MERGE->value], $stdinInput, $env);
        if ($exitCode === 0) {
            throw new \RuntimeException(sprintf(
                "Expected user-merge to fail, but it exited 0.\n--- output ---\n%s",
                $output,
            ));
        }
        $this->assertOutputContains($output, $needle);
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
    public function assertBacklogFails(array $arguments, string $needle, array $env = []): void
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
        if (!$this->context->allowRemote) {
            $parts[] = 'SOMANAGER_GIT_OFFLINE=1';
        }
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
        $parts[] = '--migrations-dir';
        $parts[] = escapeshellarg($this->relativePath($this->context->migrationsDir));
        $parts[] = '--migration-marker-file';
        $parts[] = escapeshellarg($this->relativePath($this->context->migrationMarkerPath));
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

    /**
     * Same as buildBacklogCommand but without the global --dry-run flag.
     *
     * Used for interactive commands where --dry-run would suppress prompts.
     *
     * @param list<string> $arguments Backlog command arguments
     * @param array<string, string> $env Environment variables
     */
    private function buildInteractiveBacklogCommand(array $arguments, array $env = []): string
    {
        $parts = [];
        foreach ($env as $key => $value) {
            $parts[] = sprintf('%s=%s', $key, escapeshellarg($value));
        }
        $parts[] = 'php scripts/backlog.php';
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
        $parts[] = '--migrations-dir';
        $parts[] = escapeshellarg($this->relativePath($this->context->migrationsDir));
        $parts[] = '--migration-marker-file';
        $parts[] = escapeshellarg($this->relativePath($this->context->migrationMarkerPath));
        if ($this->context->prBaseBranch() !== null) {
            $parts[] = '--pr-base-branch';
            $parts[] = escapeshellarg($this->context->prBaseBranch());
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
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create test artifact directory: {$dir}");
        }
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("Unable to write test artifact: {$path}");
        }
    }

    private function removePath(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path) && !unlink($path)) {
                throw new \RuntimeException("Unable to remove test artifact: {$path}");
            }

            return;
        }

        $items = scandir($path);
        if ($items === false) {
            throw new \RuntimeException("Unable to list test artifact directory: {$path}");
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removePath($path . '/' . $item);
        }

        if (!rmdir($path)) {
            throw new \RuntimeException("Unable to remove test artifact directory: {$path}");
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
