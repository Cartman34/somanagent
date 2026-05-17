#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 *
 * Purpose:      Convert the legacy local/backlog-board.md (markdown + pseudo-YAML meta block)
 *               into the new structured local/backlog-board.yaml format.
 * Introduced:   2026-05-17
 * Remove after: All known WAs have been regenerated against the YAML board and no operator
 *               needs to migrate a leftover .md board anymore. Tracked in doc/development/migrations.md.
 *
 * Behaviour:
 * - Reads local/backlog-board.md from the project root (where this script is invoked).
 * - Parses the legacy `## To do` / `## In progress` sections, including bracket-prefixed titles
 *   (`[type][feature-slug][task-slug] Title`) and trailing `meta:` blocks.
 * - Writes local/backlog-board.yaml with version=1 plus structured todo/active entries.
 * - Never deletes the source local/backlog-board.md — the operator must remove it manually
 *   once they have confirmed the YAML board is correct.
 * - Idempotent: if local/backlog-board.yaml already exists, the script logs and exits 0
 *   without re-reading the .md file.
 * - If a queued entry has no explicit type in its brackets, the script aborts, lists the
 *   problematic entries, and asks the operator to fix the markdown first. There is no implicit
 *   default type — this matches the strict --type contract enforced by entry-create.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use SoManAgent\Script\Backlog\Enum\BacklogTaskType;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Storage\BoardYamlStorage;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\TextSlugger;

$projectRoot = getcwd();
if ($projectRoot === false) {
    fwrite(STDERR, "Unable to resolve current working directory.\n");
    exit(1);
}

$markdownPath = $projectRoot . '/local/backlog-board.md';
$yamlPath = $projectRoot . '/local/backlog-board.yaml';

if (is_file($yamlPath)) {
    fwrite(STDOUT, "[2026-05-17-backlog-yaml] {$yamlPath} already exists — no-op (idempotent).\n");
    exit(0);
}

if (!is_file($markdownPath)) {
    fwrite(STDERR, "[2026-05-17-backlog-yaml] Source markdown board not found: {$markdownPath}\n");
    exit(1);
}

$markdown = file_get_contents($markdownPath);
if ($markdown === false) {
    fwrite(STDERR, "[2026-05-17-backlog-yaml] Unable to read {$markdownPath}.\n");
    exit(1);
}

/**
 * Parses the legacy markdown board into one BacklogBoard whose entries already carry
 * structured feature/task/type fields suitable for BoardYamlStorage::save.
 *
 * Section markers: `## To do`, `## In progress`. Suggestion section and others are ignored.
 *
 * @param string $markdown Raw board markdown
 * @return array{board: BacklogBoard, missingType: list<string>}
 */
function parseLegacyBoard(string $markdown, string $yamlPath): array {
    $service = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
    $board = new BacklogBoard($yamlPath, BacklogBoard::TITLE);
    $board->setSectionOrder([
        BacklogBoard::SECTION_TODO,
        BacklogBoard::SECTION_ACTIVE,
        BacklogBoard::SECTION_SUGGESTIONS,
    ]);

    /** @var array<string, list<string>> $sectionLines */
    $sectionLines = [BacklogBoard::SECTION_TODO => [], BacklogBoard::SECTION_ACTIVE => []];
    $currentSection = null;
    foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
        if (preg_match('/^##\s+To do\s*$/i', $line) === 1) {
            $currentSection = BacklogBoard::SECTION_TODO;
            continue;
        }
        if (preg_match('/^##\s+In progress\s*$/i', $line) === 1) {
            $currentSection = BacklogBoard::SECTION_ACTIVE;
            continue;
        }
        if (preg_match('/^##\s+\S/', $line) === 1) {
            $currentSection = null;
            continue;
        }
        if ($currentSection === null) {
            continue;
        }
        $sectionLines[$currentSection][] = $line;
    }

    $todoEntries = parseTodoEntries($sectionLines[BacklogBoard::SECTION_TODO], $service);
    $activeEntries = parseActiveEntries($sectionLines[BacklogBoard::SECTION_ACTIVE], $service);

    $missingType = [];
    foreach ($todoEntries as $entry) {
        if ($entry->getType() === null) {
            $missingType[] = '- ' . ($entry->getFeature() ?? '?') . ' "' . $entry->getText() . '"';
        }
    }

    $board->setEntries(BacklogBoard::SECTION_TODO, $todoEntries);
    $board->setEntries(BacklogBoard::SECTION_ACTIVE, $activeEntries);

    return ['board' => $board, 'missingType' => $missingType];
}

/**
 * @param list<string> $lines
 * @return list<BoardEntry>
 */
function parseTodoEntries(array $lines, BacklogBoardService $service): array {
    $entries = [];
    $buffer = null;
    foreach ($lines as $line) {
        if (preg_match('/^-\s+(.*)$/', $line, $m) === 1) {
            if ($buffer !== null) {
                $entries[] = buildTodoEntry($buffer, $service);
            }
            $buffer = ['title' => $m[1], 'lines' => []];
            continue;
        }
        if ($buffer !== null && trim($line) !== '') {
            $buffer['lines'][] = $line;
        }
    }
    if ($buffer !== null) {
        $entries[] = buildTodoEntry($buffer, $service);
    }

    return $entries;
}

/**
 * @param array{title: string, lines: list<string>} $buffer
 */
function buildTodoEntry(array $buffer, BacklogBoardService $service): BoardEntry {
    $title = $buffer['title'];
    $type = null;
    $feature = null;
    $task = null;

    [$taskType, $cleaned] = $service->extractTypePrefix($title);
    if ($taskType instanceof BacklogTaskType) {
        $type = $taskType->value;
    }
    $scoped = $service->extractScopedTaskMetadata($cleaned);
    if ($scoped !== null) {
        $feature = $scoped['featureGroup'];
        $task = $scoped['task'];
        $title = $scoped['text'];
    } else {
        $single = $service->extractSingleFeaturePrefixMetadata($cleaned);
        if ($single !== null) {
            $feature = $single['featureSlug'];
            $title = $single['text'];
        } else {
            $title = $cleaned;
        }
    }

    $entry = new BoardEntry($title, $buffer['lines']);
    $entry->setFeature($feature);
    $entry->setTask($task);
    $entry->setType($type);

    return $entry;
}

/**
 * @param list<string> $lines
 * @return list<BoardEntry>
 */
function parseActiveEntries(array $lines, BacklogBoardService $service): array {
    $entries = [];
    $current = null;
    $inMeta = false;
    foreach ($lines as $line) {
        if (preg_match('/^-\s+(.*)$/', $line, $m) === 1) {
            if ($current !== null) {
                $entries[] = buildActiveEntry($current, $service);
            }
            $current = ['title' => $m[1], 'body' => [], 'meta' => []];
            $inMeta = false;
            continue;
        }
        if ($current === null) {
            continue;
        }
        if (preg_match('/^\s*meta:\s*$/', $line) === 1) {
            $inMeta = true;
            continue;
        }
        if ($inMeta && preg_match('/^\s+([a-z][a-z0-9_-]*):\s*(.*)$/i', $line, $m) === 1) {
            $current['meta'][$m[1]] = trim($m[2]);
            continue;
        }
        if (!$inMeta && trim($line) !== '') {
            $current['body'][] = $line;
        }
    }
    if ($current !== null) {
        $entries[] = buildActiveEntry($current, $service);
    }

    return $entries;
}

/**
 * @param array{title: string, body: list<string>, meta: array<string, string>} $current
 */
function buildActiveEntry(array $current, BacklogBoardService $service): BoardEntry {
    $entry = new BoardEntry($current['title'], $current['body']);
    $meta = $current['meta'];
    $entry->setKind($meta['kind'] ?? null);
    $entry->setStage($meta['stage'] ?? null);
    $entry->setFeature($meta['feature'] ?? null);
    $entry->setTask($meta['task'] ?? null);
    $entry->setAgent($meta['agent'] ?? null);
    $entry->setReviewer($meta['reviewer'] ?? null);
    $entry->setBranch($meta['branch'] ?? null);
    $entry->setFeatureBranch($meta['feature-branch'] ?? null);
    $entry->setBase($meta['base'] ?? null);
    $entry->setPr($meta['pr'] ?? null);
    $entry->setType($meta['type'] ?? null);
    $entry->setBlocked(($meta['blocked'] ?? null) === 'yes');

    $known = ['kind', 'stage', 'feature', 'task', 'agent', 'reviewer', 'branch', 'feature-branch', 'base', 'pr', 'type', 'blocked'];
    $extras = [];
    foreach ($meta as $key => $value) {
        if (!in_array($key, $known, true)) {
            $extras[$key] = $value;
        }
    }
    $entry->setExtraMetadata($extras);

    return $entry;
}

$parsed = parseLegacyBoard($markdown, $yamlPath);
if ($parsed['missingType'] !== []) {
    fwrite(STDERR, "[2026-05-17-backlog-yaml] Aborting: the following queued entries have no explicit type in their bracket prefix.\n");
    fwrite(STDERR, "Add [feat]/[fix]/[tech] to each title in {$markdownPath} and rerun the migration:\n");
    foreach ($parsed['missingType'] as $line) {
        fwrite(STDERR, $line . "\n");
    }
    exit(1);
}

(new BoardYamlStorage())->save($parsed['board']);
fwrite(STDOUT, "[2026-05-17-backlog-yaml] Wrote {$yamlPath}. Source {$markdownPath} left in place — remove it manually when satisfied.\n");
exit(0);
