<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use SoManAgent\Script\Backlog\Enum\BacklogMetaValue;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;

/**
 * Command for listing backlog entries across all sections, with stage and format filtering.
 *
 * Supported formats: default, numbered, ref, json.
 * Supported stage filters: todo, development, review, reviewing, approved, rejected.
 */
final class BacklogListCommand extends AbstractBacklogCommand
{
    private const PSEUDO_STAGE_TODO = 'todo';

    private const VALID_STAGES = [
        self::PSEUDO_STAGE_TODO,
        BacklogBoard::STAGE_IN_PROGRESS,
        BacklogBoard::STAGE_PENDING_REVIEW,
        BacklogBoard::STAGE_REVIEWING,
        BacklogBoard::STAGE_APPROVED,
        BacklogBoard::STAGE_REJECTED,
    ];

    private const FORMAT_DEFAULT = 'default';
    private const FORMAT_NUMBERED = 'numbered';
    private const FORMAT_REF = 'ref';
    private const FORMAT_JSON = 'json';

    private const VALID_FORMATS = [
        self::FORMAT_DEFAULT,
        self::FORMAT_NUMBERED,
        self::FORMAT_REF,
        self::FORMAT_JSON,
    ];

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string|array<bool|string>> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        [$stageFilter, $noStageFilter, $format, $flat] = $this->parseOptions($options);

        $stages = $this->resolveStages($stageFilter, $noStageFilter, $flat);

        $board = $this->loadBoard();

        $sections = $this->collectSections($board, $stages);

        if ($sections === []) {
            $this->presenter->displayLine('No entry found.');

            return;
        }

        if ($format === self::FORMAT_JSON) {
            $this->outputJson($sections, $flat);

            return;
        }

        $counter = 1;
        $firstSection = true;
        foreach ($sections as $stage => $entries) {
            if (!$flat) {
                if (!$firstSection) {
                    $this->presenter->displayLine('');
                }
                $firstSection = false;
                $this->presenter->displayLine('[' . $this->getStageLabel($stage) . ']');
            }

            foreach ($entries as $entry) {
                $line = $this->formatEntry($entry, $stage, $format, $counter);
                $this->presenter->displayLine($line);
                $counter++;
            }
        }
    }

    /**
     * @param array<string, bool|string|array<bool|string>> $options
     * @return array{0: list<string>, 1: list<string>, 2: string, 3: bool}
     */
    private function parseOptions(array $options): array
    {
        $stageRaw = $options[BacklogCliOption::STAGE->value] ?? null;
        $noStageRaw = $options[BacklogCliOption::NO_STAGE->value] ?? null;
        $format = is_string($options[BacklogCliOption::FORMAT->value] ?? null) ? $options[BacklogCliOption::FORMAT->value] : self::FORMAT_DEFAULT;
        $flat = (bool) ($options[BacklogCliOption::FLAT->value] ?? false);

        if ($stageRaw !== null && $noStageRaw !== null) {
            throw new \RuntimeException('--stage and --no-stage are mutually exclusive: use only one.');
        }

        $stageFilter = $this->parseStageList($stageRaw, '--stage');
        $noStageFilter = $this->parseStageList($noStageRaw, '--no-stage');

        if (!in_array($format, self::VALID_FORMATS, true)) {
            throw new \RuntimeException(sprintf(
                'Unknown --format=%s. Allowed values: %s.',
                $format,
                implode(', ', self::VALID_FORMATS),
            ));
        }

        if ($flat) {
            if ($noStageRaw !== null) {
                throw new \RuntimeException('--flat requires --stage with exactly one value; --no-stage is not compatible.');
            }
            if (count($stageFilter) !== 1) {
                throw new \RuntimeException('--flat requires --stage with exactly one value.');
            }
        }

        return [$stageFilter, $noStageFilter, $format, $flat];
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function parseStageList(mixed $raw, string $optionName): array
    {
        if ($raw === null || $raw === false) {
            return [];
        }

        $values = is_array($raw) ? $raw : [(string) $raw];
        $result = [];
        foreach ($values as $value) {
            foreach (explode(',', (string) $value) as $stage) {
                $stage = trim($stage);
                if ($stage === '') {
                    continue;
                }
                if (!in_array($stage, self::VALID_STAGES, true)) {
                    throw new \RuntimeException(sprintf(
                        'Unknown stage %s=%s. Allowed values: %s.',
                        $optionName,
                        $stage,
                        implode(', ', self::VALID_STAGES),
                    ));
                }
                $result[] = $stage;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param list<string> $stageFilter
     * @param list<string> $noStageFilter
     * @return list<string>
     */
    private function resolveStages(array $stageFilter, array $noStageFilter, bool $flat): array
    {
        if ($stageFilter !== []) {
            return $stageFilter;
        }

        $all = self::VALID_STAGES;

        if ($noStageFilter !== []) {
            return array_values(array_filter($all, fn(string $s): bool => !in_array($s, $noStageFilter, true)));
        }

        return $all;
    }

    /**
     * @param list<string> $stages
     * @return array<string, list<BoardEntry>>
     */
    private function collectSections(BacklogBoard $board, array $stages): array
    {
        $sections = [];

        foreach ($stages as $stage) {
            if ($stage === self::PSEUDO_STAGE_TODO) {
                $entries = array_values($board->getEntries(BacklogBoard::SECTION_TODO));
                if ($entries !== []) {
                    $sections[$stage] = $entries;
                }
                continue;
            }

            $filtered = array_values(array_filter(
                $board->getEntries(BacklogBoard::SECTION_ACTIVE),
                fn(BoardEntry $entry): bool => $this->boardService->getFeatureStage($entry) === $stage
            ));

            if ($filtered !== []) {
                $sections[$stage] = $filtered;
            }
        }

        return $sections;
    }

    /**
     * @param array<string, list<BoardEntry>> $sections
     */
    private function outputJson(array $sections, bool $flat): void
    {
        $items = [];
        $counter = 1;

        foreach ($sections as $stage => $entries) {
            foreach ($entries as $entry) {
                $ref = $this->getEntryRef($entry, $stage);
                $item = [
                    'index' => $counter,
                    'stage' => $stage,
                    'ref' => $ref,
                    'kind' => $this->boardService->getEntryKind($entry),
                    'agent' => $entry->getDeveloper() ?? BacklogMetaValue::NONE->value,
                    'pr' => $this->describePr($entry),
                    'reviewer' => $entry->getReviewer() ?? BacklogMetaValue::NONE->value,
                    'title' => $entry->getText(),
                ];
                if ($entry->checkIsBlocked()) {
                    $item['blocked'] = BacklogMetaValue::YES->value;
                }
                $items[] = $item;
                $counter++;
            }
        }

        $this->presenter->displayLine((string) json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function formatEntry(BoardEntry $entry, string $stage, string $format, int $counter): string
    {
        if ($format === self::FORMAT_REF) {
            return $this->getEntryRef($entry, $stage);
        }

        $ref = $this->getEntryRef($entry, $stage);
        $parts = [
            'kind=' . $this->boardService->getEntryKind($entry),
            'agent=' . ($entry->getDeveloper() ?? BacklogMetaValue::NONE->value),
            'pr=' . $this->describePr($entry),
            'reviewer=' . ($entry->getReviewer() ?? BacklogMetaValue::NONE->value),
            'title=' . $entry->getText(),
        ];
        if ($entry->checkIsBlocked()) {
            $parts[] = 'blocked=' . BacklogMetaValue::YES->value;
        }

        $body = $ref . ' ' . implode(' ', $parts);

        return $format === self::FORMAT_NUMBERED
            ? $counter . '. ' . $body
            : '- ' . $body;
    }

    private function getEntryRef(BoardEntry $entry, string $stage): string
    {
        if ($stage === self::PSEUDO_STAGE_TODO) {
            return $this->boardService->computeQueuedEntryReference($entry);
        }

        return $this->boardService->getEntryReference($entry);
    }

    private function describePr(BoardEntry $entry): string
    {
        $pr = $entry->getPr();
        if ($pr === null || $pr === BacklogMetaValue::NONE->value) {
            return BacklogMetaValue::NONE->value;
        }

        return '#' . $pr;
    }

    private function getStageLabel(string $stage): string
    {
        if ($stage === self::PSEUDO_STAGE_TODO) {
            return 'Queued';
        }

        return $this->boardService->getStageLabel($stage);
    }
}
