<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Service;

use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BacklogReviewFile;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Model\BoardEntryMatch;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Enum\BacklogMetaValue;
use SoManAgent\Script\Backlog\Enum\BacklogTaskType;
use SoManAgent\Script\Backlog\Storage\BoardYamlStorage;
use SoManAgent\Script\Client\FilesystemClientInterface;
use SoManAgent\Script\TextSlugger;

/**
 * Service for orchestrating Backlog Board operations and entry resolution.
 */
final class BacklogBoardService
{
    public const ENTRY_KIND_FEATURE = 'feature';
    public const ENTRY_KIND_TASK = 'task';

    private const LEADING_PREFIX_PATTERN = '/^\[([A-Za-z0-9_-]+)\]\s*(.*)$/s';
    private const TASK_SCOPE_PREFIX_PATTERN = '/^\[([A-Za-z0-9_-]+)\]\[([A-Za-z0-9_-]+)\]\s*(.+)$/';
    private const SINGLE_FEATURE_PREFIX_PATTERN = '/^\[([A-Za-z0-9_-]+)\](?!\[)\s*(.+)$/';
    private const TASK_CONTRIBUTION_PREFIX_PATTERN = '/^\s*-\s*\[task:([a-z0-9-]+)\]\s*(.+)$/';

    private TextSlugger $featureSlugger;

    private FilesystemClientInterface $fs;

    private bool $dryRun;

    /**
     * Initializes the service with required dependencies.
     */
    public function __construct(TextSlugger $featureSlugger, FilesystemClientInterface $fs, bool $dryRun)
    {
        $this->featureSlugger = $featureSlugger;
        $this->fs = $fs;
        $this->dryRun = $dryRun;
    }

    /* --- Board Persistence Operations --- */

    /**
     * Loads and parses a backlog board from a YAML file.
     *
     * @return BacklogBoard
     */
    public function loadBoard(string $path): BacklogBoard
    {
        return (new BoardYamlStorage())->load($path);
    }

    /**
     * Persists a backlog board to its YAML file.
     *
     * @param BacklogBoard $board
     */
    public function saveBoard(BacklogBoard $board): void
    {
        if ($this->dryRun) {
            return;
        }

        (new BoardYamlStorage())->save($board);
    }

    /* --- Review File Persistence Operations --- */

    /**
     * Loads and parses a backlog review file.
     * @return BacklogReviewFile
     */
    public function loadReviewFile(string $path): BacklogReviewFile
    {
        $content = $this->fs->getFileContents($path);
        $lines = preg_split('/\R/', $content) ?: [];
        if ($lines === []) {
            throw new \RuntimeException("Unable to read backlog review file: {$path}");
        }

        $header = array_shift($lines);
        $reviewFile = new BacklogReviewFile($path, $header);

        $currentSection = null;
        $currentFeature = null;
        $sections = [];
        $reviews = [];

        foreach ($lines as $line) {
            if (preg_match('/^## (.+)$/', $line, $matches) === 1) {
                $currentSection = $matches[1];
                $currentFeature = null;
                $sections[$currentSection] ??= [];
                continue;
            }

            if ($currentSection === null) {
                continue;
            }

            if ($currentSection === BacklogReviewFile::SECTION_CURRENT_REVIEW && preg_match('/^### (.+)$/', $line, $matches) === 1) {
                $currentFeature = $matches[1];
                $reviews[$currentFeature] = [];
                continue;
            }

            if ($currentSection === BacklogReviewFile::SECTION_CURRENT_REVIEW && $currentFeature !== null) {
                if ($line !== '') {
                    $reviews[$currentFeature][] = $line;
                }
                continue;
            }

            $sections[$currentSection][] = $line;
        }

        if (($reviews[BacklogReviewFile::EMPTY_REVIEW_TEXT] ?? null) !== null) {
            unset($reviews[BacklogReviewFile::EMPTY_REVIEW_TEXT]);
        }

        foreach ($sections as $section => $sectionLines) {
            $sections[$section] = $this->sanitizeReviewLines($sectionLines);
        }

        $reviewFile->setSections($sections);
        $reviewFile->setReviews($reviews);

        return $reviewFile;
    }

    /**
     * Persists a backlog review file to disk.
     * @param BacklogReviewFile $reviewFile
     */
    public function saveReviewFile(BacklogReviewFile $reviewFile): void
    {
        if ($this->dryRun) {
            return;
        }

        $chunks = [$reviewFile->getHeader(), ''];
        $sections = $reviewFile->getSections();
        $reviews = $reviewFile->getReviews();

        foreach ([BacklogReviewFile::SECTION_RULES, BacklogReviewFile::SECTION_CURRENT_REVIEW] as $section) {
            $chunks[] = '## ' . $section;
            $chunks[] = '';

            if ($section === BacklogReviewFile::SECTION_CURRENT_REVIEW) {
                if ($reviews === []) {
                    $chunks[] = BacklogReviewFile::EMPTY_REVIEW_TEXT;
                } else {
                    ksort($reviews);
                    $first = true;
                    foreach ($reviews as $key => $items) {
                        if (!$first) {
                            $chunks[] = '';
                        }
                        $first = false;
                        $chunks[] = '### ' . $key;
                        $chunks[] = '';
                        foreach ($items as $item) {
                            $chunks[] = $item;
                        }
                    }
                }
            } else {
                foreach ($this->sanitizeReviewLines($sections[$section] ?? []) as $line) {
                    $chunks[] = $line;
                }
            }

            $chunks[] = '';
        }

        $this->fs->writeFilePath($reviewFile->getPath(), rtrim(implode("\n", $chunks)) . "\n");
    }

    /* --- Entry Normalization & Classification --- */

    /**
     * Normalizes text into a URL-safe slug using the feature slugger.
     * @return string
     */
    public function normalizeFeatureSlug(string $text): string
    {
        return $this->featureSlugger->slugify($text);
    }

    /**
     * Returns the entry kind (feature or task) based on its properties.
     * @param BoardEntry $entry
     * @return string
     */
    public function getEntryKind(BoardEntry $entry): string
    {
        $kind = $entry->getKind();
        if ($kind !== null) {
            return $kind;
        }

        return $entry->getTask() !== null ? self::ENTRY_KIND_TASK : self::ENTRY_KIND_FEATURE;
    }

    /**
     * Checks whether an entry is a feature entry.
     * @param BoardEntry $entry
     * @return bool
     */
    public function checkIsFeatureEntry(BoardEntry $entry): bool
    {
        return $this->getEntryKind($entry) === self::ENTRY_KIND_FEATURE;
    }

    /**
     * Checks whether an entry is a task entry.
     * @param BoardEntry $entry
     * @return bool
     */
    public function checkIsTaskEntry(BoardEntry $entry): bool
    {
        return $this->getEntryKind($entry) === self::ENTRY_KIND_TASK;
    }

    /**
     * Returns the stage for a feature entry, defaulting to in-progress if not set.
     * @param BoardEntry $entry
     * @return string
     */
    public function getFeatureStage(BoardEntry $entry): string
    {
        return $this->getEntryStage($entry) ?? BacklogBoard::STAGE_IN_PROGRESS;
    }

    /* --- Entry Resolution (from Board) --- */

    /**
     * Resolves a feature entry by its slug, throwing if not found.
     * @param BacklogBoard $board, string $feature
     * @return BoardEntryMatch
     */
    public function resolveFeature(BacklogBoard $board, string $feature): BoardEntryMatch
    {
        $match = $this->findParentFeatureEntry($board, $feature);
        if ($match === null) {
            throw new \RuntimeException("Feature not found: {$feature}");
        }

        return $match;
    }

    /**
     * Returns the stable <entry-ref> for a feature or task entry.
     */
    public function getEntryReference(BoardEntry $entry): string
    {
        if ($this->checkIsTaskEntry($entry)) {
            return $this->getTaskReviewKey($entry);
        }

        return $entry->getFeature() ?? '-';
    }

    /**
     * Finds the stable <entry-ref> for an active entry by its stored branch name.
     */
    public function findEntryReferenceByBranch(BacklogBoard $board, string $branch): ?string
    {
        $trimmed = trim($branch);
        if ($trimmed === '') {
            return null;
        }

        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            if ($entry->getBranch() === $trimmed) {
                return $this->getEntryReference($entry);
            }
        }

        return null;
    }

    /**
     * Finds a parent feature entry in the active section.
     * @param BacklogBoard $board, string $feature
     * @return ?BoardEntryMatch
     */
    public function findParentFeatureEntry(BacklogBoard $board, string $feature): ?BoardEntryMatch
    {
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->checkIsFeatureEntry($entry)) {
                continue;
            }
            if ($entry->getFeature() !== $feature) {
                continue;
            }

            return new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        return null;
    }

    /**
     * Resolves a task entry from a reference string (<entry-ref> or bare task slug).
     * @param BacklogBoard $board, string $reference, string $command
     * @return BoardEntryMatch
     */
    public function resolveTaskByReference(BacklogBoard $board, string $reference, string $command): BoardEntryMatch
    {
        $normalizedReference = trim($reference);
        if ($normalizedReference === '') {
            throw new \RuntimeException(sprintf('%s requires a task reference.', $command));
        }

        if (str_contains($normalizedReference, '/')) {
            [$feature, $task] = array_pad(explode('/', $normalizedReference, 2), 2, '');
            $feature = $this->normalizeFeatureSlug($feature);
            $task = $this->normalizeFeatureSlug($task);

            foreach ($this->findTaskEntriesByFeature($board, $feature) as $match) {
                if ($match->getEntry()->getTask() === $task) {
                    return $match;
                }
            }

            throw new \RuntimeException($this->taskNotFoundMessage($board, $normalizedReference, sprintf('%s/%s', $feature, $task)));
        }

        $task = $this->normalizeFeatureSlug($normalizedReference);
        $matches = $this->findTaskEntriesByTaskSlug($board, $task);
        if ($matches === []) {
            throw new \RuntimeException($this->taskNotFoundMessage($board, $normalizedReference, $task));
        }
        if (count($matches) > 1) {
            throw new \RuntimeException(sprintf(
                '%s requires a full <entry-ref> because task slug %s is not unique.',
                $command,
                $task,
            ));
        }

        return $matches[0];
    }

    /**
     * Resolves an active feature or task by its stable <entry-ref>.
     *
     * A slash identifies a task (`feature/task`). A slash-less reference is first
     * matched as a feature slug, with an ambiguity guard when the same slug also
     * identifies one or more active tasks.
     */
    public function resolveActiveEntryByReference(BacklogBoard $board, string $reference, string $command): BoardEntryMatch
    {
        $trimmed = trim($reference);
        if ($trimmed === '') {
            throw new \RuntimeException(sprintf('%s requires an explicit <entry-ref>.', $command));
        }

        if (str_contains($trimmed, '/')) {
            return $this->resolveTaskByReference($board, $trimmed, $command);
        }

        $slug = $this->normalizeFeatureSlug($trimmed);
        $featureMatch = $this->findParentFeatureEntry($board, $slug);
        $taskMatches = $this->findTaskEntriesByTaskSlug($board, $slug);

        if ($featureMatch !== null && $taskMatches !== []) {
            throw new \RuntimeException(sprintf(
                'Ambiguous reference %s: matches both a feature and a task. Use a full <entry-ref> to disambiguate.',
                $trimmed,
            ));
        }

        if ($featureMatch !== null) {
            return $featureMatch;
        }

        if ($taskMatches !== []) {
            if (count($taskMatches) > 1) {
                throw new \RuntimeException(sprintf(
                    '%s requires a full <entry-ref> because task slug %s is not unique.',
                    $command,
                    $slug,
                ));
            }

            return $taskMatches[0];
        }

        throw new \RuntimeException(sprintf('No active entry found for reference: %s', $trimmed));
    }

    private function taskNotFoundMessage(BacklogBoard $board, string $providedReference, string $normalizedReference): string
    {
        $message = sprintf('Task not found: %s', $normalizedReference);
        $suggestion = $this->findEntryReferenceByBranch($board, $providedReference);
        if ($suggestion !== null) {
            $message .= sprintf('. Did you mean %s?', $suggestion);
        }

        return $message;
    }

    /**
     * Returns all active entries (features and tasks) in the approved stage, in board order.
     *
     * @return array<int, BoardEntryMatch>
     */
    public function fetchApprovedEntries(BacklogBoard $board): array
    {
        $matches = [];
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if ($this->getEntryStage($entry) === BacklogBoard::STAGE_APPROVED && !$entry->checkIsBlocked()) {
                $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
            }
        }

        return $matches;
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function fetchFeaturesByStage(BacklogBoard $board, string $stage): array
    {
        $normalizedStage = $this->getNormalizedStage($stage);
        if ($normalizedStage === null) {
            return [];
        }

        $matches = [];

        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->checkIsFeatureEntry($entry)) {
                continue;
            }
            if ($this->getEntryStage($entry) !== $normalizedStage) {
                continue;
            }

            $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        return $matches;
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function findTaskEntriesByFeature(BacklogBoard $board, string $feature): array
    {
        $matches = [];
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->checkIsTaskEntry($entry)) {
                continue;
            }
            if ($entry->getFeature() !== $feature) {
                continue;
            }

            $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        return $matches;
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function findTaskEntriesByTaskSlug(BacklogBoard $board, string $task): array
    {
        $matches = [];
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->checkIsTaskEntry($entry)) {
                continue;
            }
            if ($entry->getTask() !== $task) {
                continue;
            }

            $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        return $matches;
    }

    /**
     * Computes the stable reference identifier of a queued todo entry from its fields.
     *
     * Returns `feature/task` for scoped tasks and `feature` for plain features.
     * The reference stays valid across todo reorderings and is the only trustworthy
     * identity for mutations on queued entries.
     */
    public function computeQueuedEntryReference(BoardEntry $entry): string
    {
        $feature = $entry->getFeature();
        $task = $entry->getTask();

        if ($feature !== null && $task !== null) {
            return $feature . '/' . $task;
        }

        if ($feature !== null) {
            return $feature;
        }

        return $this->normalizeFeatureSlug($entry->getText());
    }

    /**
     * Resolves a queued todo entry by its stable reference, throwing when missing or ambiguous.
     *
     * The reference is an `<entry-ref>` as returned by
     * {@see computeQueuedEntryReference}. Both sides are normalized through the feature
     * slugger so users can pass any reasonable casing or hyphenation.
     *
     * @return array{0: int, 1: BoardEntry} Tuple [0-based index in todo section, matched entry]
     */
    public function resolveQueuedEntryByReference(BacklogBoard $board, string $reference, string $command): array
    {
        $normalized = $this->normalizeQueuedEntryReference($reference, $command);
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        $matches = [];

        foreach ($entries as $index => $entry) {
            if ($this->computeQueuedEntryReference($entry) === $normalized) {
                $matches[] = [$index, $entry];
            }
        }

        if ($matches === []) {
            throw new \RuntimeException(sprintf(
                'No queued task found for reference: %s. Run list --stage=todo to see queued references.',
                $normalized,
            ));
        }

        if (count($matches) > 1) {
            throw new \RuntimeException(sprintf(
                'Ambiguous queued reference %s: matches %d queued tasks. Rename one of them or pass a more specific entry reference.',
                $normalized,
                count($matches),
            ));
        }

        return $matches[0];
    }

    /**
     * Normalizes a user-provided queued reference into the canonical slug form used by
     * {@see computeQueuedEntryReference}.
     */
    public function normalizeQueuedEntryReference(string $reference, string $command): string
    {
        $trimmed = trim($reference);
        if ($trimmed === '') {
            throw new \RuntimeException(sprintf('%s requires a queued task reference (<entry-ref>).', $command));
        }

        if (str_contains($trimmed, '/')) {
            [$feature, $task] = array_pad(explode('/', $trimmed, 2), 2, '');
            $feature = $this->normalizeFeatureSlug($feature);
            $task = $this->normalizeFeatureSlug($task);
            if ($feature === '' || $task === '') {
                throw new \RuntimeException(sprintf('%s requires a non-empty feature and task slug in <entry-ref>.', $command));
            }

            return $feature . '/' . $task;
        }

        $slug = $this->normalizeFeatureSlug($trimmed);
        if ($slug === '') {
            throw new \RuntimeException(sprintf('%s requires a non-empty reference slug.', $command));
        }

        return $slug;
    }

    /**
     * Resolves the single active task for an agent, throwing on none or multiple.
     * @param BacklogBoard $board, string $agent
     * @return BoardEntryMatch
     */
    public function resolveSingleTaskForAgent(BacklogBoard $board, string $agent): BoardEntryMatch
    {
        $matches = $this->findTaskEntriesByAgent($board, $agent);
        if ($matches === []) {
            throw new \RuntimeException("Agent {$agent} has no active task.");
        }
        if (count($matches) > 1) {
            throw new \RuntimeException("Agent {$agent} has multiple active tasks.");
        }

        return $matches[0];
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function findTaskEntriesByAgent(BacklogBoard $board, string $agent): array
    {
        $matches = [];
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->checkIsTaskEntry($entry) || $entry->getDeveloper() !== $agent) {
                continue;
            }

            $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        return $matches;
    }

    /**
     * Resolves the single active feature for an agent, throwing on none or multiple.
     * @param BacklogBoard $board, string $agent
     * @return BoardEntryMatch
     */
    public function resolveSingleFeatureForAgent(BacklogBoard $board, string $agent): BoardEntryMatch
    {
        $matches = $this->findFeatureEntriesByAgent($board, $agent);
        if ($matches === []) {
            throw new \RuntimeException("Agent {$agent} has no active feature.");
        }
        if (count($matches) > 1) {
            throw new \RuntimeException("Agent {$agent} has multiple active features.");
        }

        return $matches[0];
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function findFeatureEntriesByAgent(BacklogBoard $board, string $agent): array
    {
        $matches = [];
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->checkIsFeatureEntry($entry) || $entry->getDeveloper() !== $agent) {
                continue;
            }

            $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        return $matches;
    }

    /**
     * Returns all active entries (task and feature kinds) assigned to an agent.
     *
     * @return array<int, BoardEntryMatch>
     */
    public function findActiveEntriesByAgent(BacklogBoard $board, string $agent): array
    {
        return array_merge(
            $this->findTaskEntriesByAgent($board, $agent),
            $this->findFeatureEntriesByAgent($board, $agent)
        );
    }

    /**
     * Returns the single entry owned by the reviewer at stage=reviewing, or null.
     *
     * @param BacklogBoard $board
     * @param string $reviewer The reviewer agent code (e.g. r01)
     * @return BoardEntryMatch|null
     */
    public function findReviewingEntryByReviewer(BacklogBoard $board, string $reviewer): ?BoardEntryMatch
    {
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if ($this->getNormalizedStage($entry->getStage()) !== BacklogBoard::STAGE_REVIEWING) {
                continue;
            }
            if ($entry->getReviewer() === $reviewer) {
                return new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
            }
        }

        return null;
    }

    /**
     * Builds an informative conflict error when an agent already has an active entry.
     *
     * @param array<int, BoardEntryMatch> $activeEntries
     */
    public function describeActiveEntryConflict(array $activeEntries, string $agent): string
    {
        $count = count($activeEntries);
        $lines = [
            sprintf(
                'Agent %s already has %s active %s. One entry per agent is allowed.',
                $agent,
                $count === 1 ? 'an' : $count,
                $count === 1 ? 'entry' : 'entries'
            ),
        ];

        $resolveEntry = null;
        foreach ($activeEntries as $match) {
            $entry = $match->getEntry();
            $isTask = $this->checkIsTaskEntry($entry);
            $kind = $this->getEntryKind($entry);
            $stage = $this->getFeatureStage($entry);
            if ($isTask) {
                $ref = "kind={$kind}  feature={$entry->getFeature()}  task={$entry->getTask()}  stage={$stage}  branch={$entry->getBranch()}";
            } else {
                $ref = "kind={$kind}  feature={$entry->getFeature()}  stage={$stage}  branch={$entry->getBranch()}";
            }
            $lines[] = "  {$ref}";
            if ($resolveEntry === null || $isTask) {
                $resolveEntry = $entry;
            }
        }

        if ($resolveEntry !== null) {
            $lines[] = 'Resolve:';
            $lines[] = '  ' . $this->resolveActiveEntryNextStep($resolveEntry, $agent);
        }

        $lines[] = "Details: php scripts/backlog.php status --agent={$agent}";

        return implode("\n", $lines);
    }

    private function resolveActiveEntryNextStep(BoardEntry $entry, string $agent): string
    {
        $stage = $this->getFeatureStage($entry);
        if ($this->checkIsTaskEntry($entry)) {
            return match ($stage) {
                BacklogBoard::STAGE_IN_PROGRESS => "run `review-request --agent={$agent}` to submit the task for review",
                BacklogBoard::STAGE_PENDING_REVIEW   => 'wait for reviewer action on the active task',
                BacklogBoard::STAGE_REJECTED    => "run `rework --agent={$agent}` to resume development on the rejected task",
                BacklogBoard::STAGE_APPROVED    => "run `merge <entry-ref> --agent={$agent}` to merge the task into its parent feature",
                default                         => 'check status',
            };
        }

        return match ($stage) {
            BacklogBoard::STAGE_IN_PROGRESS => "run `review-request --agent={$agent}` to submit for review, or `release --agent={$agent}` if no commits were made",
            BacklogBoard::STAGE_PENDING_REVIEW   => 'wait for reviewer action on the active feature',
            BacklogBoard::STAGE_REJECTED    => "run `rework --agent={$agent}` to resume development on the rejected feature",
            BacklogBoard::STAGE_APPROVED    => 'wait for the manager to merge the feature',
            default                         => 'check status',
        };
    }

    /**
     * @return array<int, BoardEntryMatch>
     */
    public function fetchReservedTasks(BacklogBoard $board, ?string $agent = null, ?string $feature = null): array
    {
        $matches = [];

        foreach ($board->getEntries(BacklogBoard::SECTION_TODO) as $index => $entry) {
            if ($entry->getDeveloper() === null) {
                continue;
            }

            if ($agent !== null && $entry->getDeveloper() !== $agent) {
                continue;
            }

            if ($feature !== null && $entry->getFeature() !== $feature) {
                continue;
            }

            $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_TODO, $index, $entry);
        }

        return $matches;
    }

    /* --- Board Entry Markdown Parsing & Formatting --- */

    /**
     * @param array<string, string> $metadata
     */
    public function hydrateEntryFromMetadata(BoardEntry $entry, array $metadata): void
    {
        $entry->setDeveloper($this->sanitizeString($metadata[BoardEntry::META_DEVELOPER] ?? null));
        $entry->setBase($this->sanitizeString($metadata[BoardEntry::META_BASE] ?? null));
        $entry->setBlocked($this->sanitizeString($metadata[BoardEntry::META_BLOCKED] ?? null) === BacklogMetaValue::YES->value);
        $entry->setBranch($this->sanitizeString($metadata[BoardEntry::META_BRANCH] ?? null));
        $entry->setFeature($this->sanitizeString($metadata[BoardEntry::META_FEATURE] ?? null));
        $entry->setFeatureBranch($this->sanitizeString($metadata[BoardEntry::META_FEATURE_BRANCH] ?? null));
        $entry->setKind($this->sanitizeString($metadata[BoardEntry::META_KIND] ?? null));
        $entry->setPr($this->sanitizeString($metadata[BoardEntry::META_PR] ?? null));
        $entry->setReviewer($this->sanitizeString($metadata[BoardEntry::META_REVIEWER] ?? null));
        $entry->setScope($this->sanitizeString($metadata[BoardEntry::META_SCOPE] ?? null));
        $entry->setStage($this->sanitizeString($metadata[BoardEntry::META_STAGE] ?? null));
        $entry->setTask($this->sanitizeString($metadata[BoardEntry::META_TASK] ?? null));
        $entry->setType($this->sanitizeString($metadata[BoardEntry::META_TYPE] ?? null));

        $knownKeys = [
            BoardEntry::META_DEVELOPER, BoardEntry::META_BASE, BoardEntry::META_BLOCKED, BoardEntry::META_BRANCH,
            BoardEntry::META_FEATURE, BoardEntry::META_FEATURE_BRANCH, BoardEntry::META_KIND,
            BoardEntry::META_PR, BoardEntry::META_REVIEWER, BoardEntry::META_SCOPE, BoardEntry::META_STAGE, BoardEntry::META_TASK, BoardEntry::META_TYPE,
        ];

        $extraMetadata = array_diff_key($metadata, array_flip($knownKeys));
        $extraMetadata = array_filter(
            array_map($this->sanitizeString(...), $extraMetadata),
            static fn(?string $value): bool => $value !== null
        );
        $entry->setExtraMetadata($extraMetadata);
    }

    /**
     * Trims whitespace from a string, returning null if empty.
     * @param ?string $value
     * @return ?string
     */
    public function sanitizeString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /* --- Stage Logic --- */

    /**
     * Normalizes a stage string to a known constant, or returns null if invalid.
     * @param ?string $stage
     * @return ?string
     */
    public function getNormalizedStage(?string $stage): ?string
    {
        $stage = trim((string) $stage);

        return match ($stage) {
            BacklogBoard::STAGE_IN_PROGRESS => BacklogBoard::STAGE_IN_PROGRESS,
            BacklogBoard::STAGE_PENDING_REVIEW => BacklogBoard::STAGE_PENDING_REVIEW,
            BacklogBoard::STAGE_REVIEWING => BacklogBoard::STAGE_REVIEWING,
            BacklogBoard::STAGE_REJECTED => BacklogBoard::STAGE_REJECTED,
            BacklogBoard::STAGE_APPROVED => BacklogBoard::STAGE_APPROVED,
            default => null,
        };
    }

    /**
     * Returns a human-readable label for a stage constant.
     * @param string $stage
     * @return string
     */
    public function getStageLabel(string $stage): string
    {
        return match ($this->getNormalizedStage($stage)) {
            BacklogBoard::STAGE_IN_PROGRESS => 'In development',
            BacklogBoard::STAGE_PENDING_REVIEW => 'Pending review',
            BacklogBoard::STAGE_REVIEWING => 'Reviewing',
            BacklogBoard::STAGE_REJECTED => 'Rejected',
            BacklogBoard::STAGE_APPROVED => 'Approved',
            default => $stage,
        };
    }

    /**
     * @return array<int, string>
     */
    public function getActiveStages(): array
    {
        return [
            BacklogBoard::STAGE_IN_PROGRESS,
            BacklogBoard::STAGE_PENDING_REVIEW,
            BacklogBoard::STAGE_REVIEWING,
            BacklogBoard::STAGE_REJECTED,
            BacklogBoard::STAGE_APPROVED,
        ];
    }

    /**
     * Returns the normalized stage for an entry, or null if not set.
     * @param BoardEntry $entry
     * @return ?string
     */
    public function getEntryStage(BoardEntry $entry): ?string
    {
        return $this->getNormalizedStage($entry->getStage());
    }

    /* --- Mutations & Complex Logic --- */

    /**
     * Extracts an optional task type prefix from anywhere in the leading bracket sequence.
     *
     * Recognizes any case of {@see BacklogTaskType} as the type prefix. Other leading
     * `[token]` brackets are kept verbatim (they remain feature and task slug prefixes that
     * downstream `extractScopedTaskMetadata` / `extractSingleFeaturePrefixMetadata`
     * still resolve). Only one type prefix is allowed; duplicates raise a RuntimeException.
     *
     * @return array{0: ?BacklogTaskType, 1: string} Tuple [type|null, textWithoutType]
     */
    public function extractTypePrefix(string $text): array
    {
        $remaining = trim($text);
        $prefixes = [];

        while (preg_match(self::LEADING_PREFIX_PATTERN, $remaining, $matches) === 1) {
            $prefixes[] = $matches[1];
            $remaining = ltrim($matches[2]);
        }

        $type = null;
        $kept = [];

        foreach ($prefixes as $token) {
            $candidate = BacklogTaskType::tryFromToken($token);
            if ($candidate === null) {
                $kept[] = $token;
                continue;
            }
            if ($type !== null) {
                throw new \RuntimeException(sprintf(
                    'Duplicate task type prefix: [%s] cannot follow [%s]. Allowed types: %s.',
                    $candidate->value,
                    $type->value,
                    BacklogTaskType::tokenList(),
                ));
            }
            $type = $candidate;
        }

        $cleanedPrefix = '';
        foreach ($kept as $token) {
            $cleanedPrefix .= '[' . $token . ']';
        }

        if ($cleanedPrefix === '') {
            $cleaned = $remaining;
        } elseif ($remaining === '') {
            $cleaned = $cleanedPrefix;
        } else {
            $cleaned = $cleanedPrefix . ' ' . $remaining;
        }

        return [$type, trim($cleaned)];
    }


    /**
     * Returns the first entry in the TODO section, or null if empty.
     * @param BacklogBoard $board
     * @return ?BoardEntryMatch
     */
    public function fetchNextTodoTask(BacklogBoard $board): ?BoardEntryMatch
    {
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        if ($entries === []) {
            return null;
        }

        return new BoardEntryMatch(BacklogBoard::SECTION_TODO, 0, $entries[0]);
    }

    /**
     * @param list<BoardEntryMatch> $reserved
     */
    public function removeReservedTasks(BacklogBoard $board, array $reserved): void
    {
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        $indexes = array_map(static fn(BoardEntryMatch $item): int => $item->getIndex(), $reserved);
        rsort($indexes);

        foreach ($indexes as $index) {
            array_splice($entries, $index, 1);
        }

        $board->setEntries(BacklogBoard::SECTION_TODO, array_values($entries));
    }

    /**
     * Removes an entry from the active section at the specified index.
     * @param BacklogBoard $board, int $index
     */
    public function removeActiveEntryAt(BacklogBoard $board, int $index): void
    {
        $entries = $board->getEntries(BacklogBoard::SECTION_ACTIVE);
        array_splice($entries, $index, 1);
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, array_values($entries));
    }

    /**
     * Deletes a feature and all its associated entries from the board.
     * @param BacklogBoard $board, string $feature
     */
    public function deleteFeature(BacklogBoard $board, string $feature): void
    {
        $match = $this->findParentFeatureEntry($board, $feature);
        if ($match === null) {
            return;
        }

        $entries = $board->getEntries($match->getSection());
        array_splice($entries, $match->getIndex(), 1);
        $board->setEntries($match->getSection(), array_values($entries));
    }

    /**
     * Clears agent reservations from TODO entries, optionally filtered by feature.
     *
     * @param BacklogBoard $board, string $agent, ?string $feature
     */
    public function clearAgentReservations(BacklogBoard $board, string $agent, ?string $feature = null): void
    {
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);

        foreach ($entries as $entry) {
            if ($entry->getDeveloper() !== $agent) {
                continue;
            }

            if ($feature !== null && $entry->getFeature() !== $feature) {
                continue;
            }

            $entry->setDeveloper(null);
        }

        $board->setEntries(BacklogBoard::SECTION_TODO, $entries);
    }

    /**
     * Adds a task entry as a contribution block to a feature entry.
     * @param BoardEntry $featureEntry, BoardEntry $taskEntry
     */
    public function appendTaskContribution(BoardEntry $featureEntry, BoardEntry $taskEntry): void
    {
        $blocks = $this->getFeatureContributionBlocks($featureEntry);
        $task = (string) ($taskEntry->getTask() ?? '');
        foreach ($blocks as $block) {
            if ($block['task'] === $task) {
                return;
            }
        }

        $blocks[] = [
            'task' => $task,
            'text' => $taskEntry->getText(),
            'extraLines' => array_values($taskEntry->getExtraLines()),
        ];
        $this->rebuildFeatureFromContributionBlocks($featureEntry, $blocks);
    }

    /**
     * Updates the text of an existing task contribution line in the feature container.
     *
     * @param BoardEntry $featureEntry
     * @param string $taskSlug
     * @param string $newText
     */
    public function updateTaskContributionText(BoardEntry $featureEntry, string $taskSlug, string $newText): void
    {
        $blocks = $this->getFeatureContributionBlocks($featureEntry);
        foreach ($blocks as &$block) {
            if ($block['task'] === $taskSlug) {
                $block['text'] = $newText;
                $this->rebuildFeatureFromContributionBlocks($featureEntry, $blocks);

                return;
            }
        }
    }

    /**
     * Removes a task contribution from a feature entry, returning true if removed and others remain.
     * @param BoardEntry $featureEntry, BoardEntry $taskEntry
     * @return bool
     */
    public function removeTaskContribution(BoardEntry $featureEntry, BoardEntry $taskEntry): bool
    {
        $blocks = $this->getFeatureContributionBlocks($featureEntry);
        $remaining = [];
        $removed = false;
        $task = (string) ($taskEntry->getTask() ?? '');

        foreach ($blocks as $block) {
            if (!$removed && $block['task'] === $task) {
                $removed = true;
                continue;
            }

            $remaining[] = $block;
        }

        if (!$removed) {
            return false;
        }

        $this->rebuildFeatureFromContributionBlocks($featureEntry, $remaining);

        return $remaining !== [];
    }

    /* --- Validations & Helpers --- */

    /**
     * Throws if the feature has any active task branches.
     * @param BacklogBoard $board, string $feature, string $command
     */
    public function assertNoActiveTasksForFeature(BacklogBoard $board, string $feature, string $command): void
    {
        if ($this->findTaskEntriesByFeature($board, $feature) !== []) {
            throw new \RuntimeException(sprintf(
                '%s cannot continue while feature %s still has active task branches.',
                $command,
                $feature,
            ));
        }
    }

    /**
     * @return array{featureGroup: string, task: string, text: string}|null
     */
    public function extractScopedTaskMetadata(string $text): ?array
    {
        if (preg_match(self::TASK_SCOPE_PREFIX_PATTERN, trim($text), $matches) !== 1) {
            return null;
        }

        return [
            'featureGroup' => $this->normalizeFeatureSlug($matches[1]),
            'task' => $this->normalizeFeatureSlug($matches[2]),
            'text' => trim($matches[3]),
        ];
    }

    /**
     * @return array{featureSlug: string, text: string}|null
     */
    public function extractSingleFeaturePrefixMetadata(string $text): ?array
    {
        if (preg_match(self::SINGLE_FEATURE_PREFIX_PATTERN, trim($text), $matches) !== 1) {
            return null;
        }

        return [
            'featureSlug' => $this->normalizeFeatureSlug($matches[1]),
            'text' => trim($matches[2]),
        ];
    }

    /**
     * @return list<array{task: string, text: string, extraLines: list<string>}>
     */
    public function getFeatureContributionBlocks(BoardEntry $featureEntry): array
    {
        /** @var list<array{task: string, text: string, extraLines: list<string>}> $blocks */
        $blocks = [];
        $currentIndex = null;

        foreach ($featureEntry->getExtraLines() as $line) {
            if (preg_match(self::TASK_CONTRIBUTION_PREFIX_PATTERN, trim($line), $matches) === 1) {
                $blocks[] = ['task' => $matches[1], 'text' => trim($matches[2]), 'extraLines' => []];
                $currentIndex = array_key_last($blocks);
                continue;
            }

            if ($currentIndex === null) {
                continue;
            }

            $block = $blocks[$currentIndex];
            $block['extraLines'][] = '  ' . ltrim($line);
            $blocks[$currentIndex] = $block;
        }

        return $blocks;
    }

    /**
     * @param list<array{task: string, text: string, extraLines: list<string>}> $blocks
     */
    private function rebuildFeatureFromContributionBlocks(BoardEntry $featureEntry, array $blocks): void
    {
        $lines = [];
        foreach ($blocks as $block) {
            $lines[] = sprintf('  - [task:%s] %s', $block['task'], $block['text']);
            foreach ($block['extraLines'] as $line) {
                $lines[] = '    ' . ltrim($line);
            }
        }

        $featureEntry->setExtraLines($lines);
    }

    /**
     * Generates a review key string from feature and task slugs.
     * @param BoardEntry $entry
     * @return string
     */
    public function getTaskReviewKey(BoardEntry $entry): string
    {
        return sprintf(
            '%s/%s',
            $entry->getFeature() ?? '-',
            $entry->getTask() ?? '-',
        );
    }

    /**
     * Resolves the canonical {@see BacklogTaskType} for an entry being started.
     *
     * @param BoardEntry $first
     * @param ?BoardEntry $parent
     * @param string $override
     */
    public function resolveTaskTypeOrDefault(BoardEntry $first, ?BoardEntry $parent, string $override): BacklogTaskType
    {
        if ($override !== '') {
            $resolved = BacklogTaskType::tryFromToken($override);
            if ($resolved === null) {
                throw new \RuntimeException(sprintf(
                    'Unknown --branch-type=%s. Allowed values: %s.',
                    $override,
                    BacklogTaskType::tokenList(),
                ));
            }

            return $resolved;
        }

        if ($first->getType() !== null) {
            $resolved = BacklogTaskType::tryFromToken($first->getType());
            if ($resolved === null) {
                throw new \RuntimeException(sprintf(
                    'Unknown task type metadata: %s. Allowed values: %s.',
                    $first->getType(),
                    BacklogTaskType::tokenList(),
                ));
            }

            return $resolved;
        }

        if ($parent !== null && $parent->getType() !== null) {
            $resolved = BacklogTaskType::tryFromToken($parent->getType());
            if ($resolved === null) {
                throw new \RuntimeException(sprintf(
                    'Unknown parent task type metadata: %s. Allowed values: %s.',
                    $parent->getType(),
                    BacklogTaskType::tokenList(),
                ));
            }

            return $resolved;
        }

        return BacklogTaskType::FEAT;
    }

    /**
     * Resets a feature entry to in-progress and clears reviewer metadata if it is in any review-like stage.
     * @param BoardEntry $featureEntry
     */
    public function invalidateFeatureReviewState(BoardEntry $featureEntry): void
    {
        if ($this->getFeatureStage($featureEntry) !== BacklogBoard::STAGE_IN_PROGRESS) {
            $featureEntry->setStage(BacklogBoard::STAGE_IN_PROGRESS);
            $featureEntry->setReviewer(null);
        }
    }

    /**
     * Returns true when the feature is in a review-like stage (review, reviewing, or approved).
     * @param BoardEntry $featureEntry
     * @return bool
     */
    public function isFeatureInReviewLikeStage(BoardEntry $featureEntry): bool
    {
        return in_array($this->getFeatureStage($featureEntry), [
            BacklogBoard::STAGE_PENDING_REVIEW,
            BacklogBoard::STAGE_REVIEWING,
            BacklogBoard::STAGE_APPROVED,
        ], true);
    }

    /**
     * Returns queued todo entries that are scoped child tasks of the given feature.
     *
     * @return array<int, BoardEntryMatch>
     */
    public function findQueuedTasksForFeature(BacklogBoard $board, string $feature): array
    {
        $matches = [];
        foreach ($board->getEntries(BacklogBoard::SECTION_TODO) as $index => $entry) {
            if ($entry->getFeature() === $feature && $entry->getTask() !== null) {
                $matches[] = new BoardEntryMatch(BacklogBoard::SECTION_TODO, $index, $entry);
            }
        }

        return $matches;
    }

    /**
     * Throws if the feature has any queued (not yet started) child tasks.
     * @param BacklogBoard $board, string $feature, string $command
     */
    public function assertNoQueuedTasksForFeature(BacklogBoard $board, string $feature, string $command): void
    {
        if ($this->findQueuedTasksForFeature($board, $feature) !== []) {
            throw new \RuntimeException(sprintf(
                '%s cannot continue while feature %s has queued child tasks not yet started.',
                $command,
                $feature,
            ));
        }
    }

    /**
     * @param array<string> $lines
     * @return array<string>
     */
    private function sanitizeReviewLines(array $lines): array
    {
        $normalized = [];
        $previousWasBlank = false;

        foreach ($lines as $line) {
            $isBlank = trim($line) === '';
            if ($isBlank) {
                if ($previousWasBlank) {
                    continue;
                }

                $normalized[] = '';
                $previousWasBlank = true;

                continue;
            }

            $normalized[] = $line;
            $previousWasBlank = false;
        }

        while ($normalized !== [] && $normalized[0] === '') {
            array_shift($normalized);
        }

        while ($normalized !== [] && end($normalized) === '') {
            array_pop($normalized);
        }

        return $normalized;
    }
}
