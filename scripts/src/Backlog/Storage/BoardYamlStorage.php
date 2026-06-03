<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Storage;

use SoManAgent\Script\Backlog\Enum\BacklogMetaValue;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads and writes the backlog board as a YAML file.
 *
 * YAML format (version 1):
 * ```
 * version: 1
 * todo:
 *   - feature: my-feature
 *     task: my-task        # optional
 *     type: feat           # optional
 *     developer: d01       # optional reservation
 *     title: Task title
 *     body: |              # optional body lines (without 2-space board prefix)
 *       - Sub-task 1
 * active:
 *   - kind: feature
 *     stage: development
 *     feature: my-feature
 *     developer: d01       # null-valued fields are omitted
 *     branch: feat/my-feature
 *     ...
 *     database: my_db      # extra metadata at the end
 * ```
 *
 * Field ordering for active entries mirrors the markdown meta block order so that
 * existing {@see BacklogScriptTestDriver::replaceBoardText} patterns continue to
 * work as substrings in the YAML file.
 */
final class BoardYamlStorage
{
    private const VERSION = 1;
    private const FIELD_FEATURE_BRANCH = 'feature-branch';

    private const KNOWN_ACTIVE_FIELDS = [
        'kind', 'stage', 'feature', 'task', 'developer', 'reviewer',
        'branch', self::FIELD_FEATURE_BRANCH, 'base', 'pr', 'blocked', 'scope', 'type', 'title', 'body',
    ];

    /**
     * Loads a BacklogBoard from a YAML file.
     */
    public function load(string $path): BacklogBoard
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Unable to read backlog board: {$path}");
        }

        $data = Yaml::parse($content) ?: [];

        $board = new BacklogBoard($path);

        $board->setEntries(BacklogBoard::SECTION_TODO, $this->loadTodoEntries($data['todo'] ?? []));
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, $this->loadActiveEntries($data['active'] ?? []));
        $board->setReviewResumeEnabled($this->loadReviewResumeEnabled($data['config'] ?? null));

        return $board;
    }

    /**
     * Persists a BacklogBoard to its YAML file.
     */
    public function save(BacklogBoard $board): void
    {
        $data = [
            'version' => self::VERSION,
        ];

        $config = $this->dumpConfig($board);
        if ($config !== null) {
            $data['config'] = $config;
        }

        $data['todo'] = $this->dumpTodoEntries($board->getEntries(BacklogBoard::SECTION_TODO));
        $data['active'] = $this->dumpActiveEntries($board->getEntries(BacklogBoard::SECTION_ACTIVE));

        $yaml = self::compactNestedListMappings(
            Yaml::dump($data, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE),
        );
        if (file_put_contents($board->getPath(), $yaml) === false) {
            throw new \RuntimeException("Unable to write backlog board: {$board->getPath()}");
        }
    }

    /**
     * Returns the initial YAML content for a fresh board.
     */
    public static function initialContent(): string
    {
        return self::compactNestedListMappings(Yaml::dump([
            'version' => self::VERSION,
            'todo' => [],
            'active' => [],
        ], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
    }

    /**
     * Collapses Symfony YAML's two-line list-of-mapping shape (`-\n    key: val`) into the
     * canonical one-line form (`- key: val`).
     *
     * Equivalent to passing Yaml::DUMP_COMPACT_NESTED_MAPPING but applied as a textual
     * post-process so the dump call stays within the bitmask set known to PHPStan stubs.
     */
    private static function compactNestedListMappings(string $yaml): string
    {
        return (string) preg_replace('/^(\s*)-\n\1  (\S)/m', '$1- $2', $yaml);
    }

    /**
     * @param array<mixed> $items
     * @return array<int, BoardEntry>
     */
    private function loadTodoEntries(array $items): array
    {
        $entries = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $entry = new BoardEntry((string) ($item['title'] ?? ''));
            $entry->setFeature($this->str($item['feature'] ?? null));
            $entry->setTask($this->str($item['task'] ?? null));
            $entry->setDeveloper($this->str($item['developer'] ?? null));
            $entry->setScope($this->str($item['scope'] ?? null));
            $entry->setType($this->str($item['type'] ?? null));
            $entry->setExtraLines($this->loadBodyLines($item['body'] ?? null));
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param array<mixed> $items
     * @return array<int, BoardEntry>
     */
    private function loadActiveEntries(array $items): array
    {
        $entries = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $entry = new BoardEntry((string) ($item['title'] ?? ''));
            $entry->setKind($this->str($item['kind'] ?? null));
            $entry->setStage($this->str($item['stage'] ?? null));
            $entry->setFeature($this->str($item['feature'] ?? null));
            $entry->setTask($this->str($item['task'] ?? null));
            $entry->setDeveloper($this->str($item['developer'] ?? null));
            $entry->setReviewer($this->str($item['reviewer'] ?? null));
            $entry->setBranch($this->str($item['branch'] ?? null));
            $entry->setFeatureBranch($this->str($item[self::FIELD_FEATURE_BRANCH] ?? null));
            $entry->setBase($this->str($item['base'] ?? null));
            $entry->setPr($this->str($item['pr'] ?? null));
            $entry->setBlocked(($item['blocked'] ?? null) === BacklogMetaValue::YES->value);
            $entry->setScope($this->str($item['scope'] ?? null));
            $entry->setType($this->str($item['type'] ?? null));
            $entry->setExtraLines($this->loadBodyLines($item['body'] ?? null));

            $extra = array_diff_key($item, array_flip(self::KNOWN_ACTIVE_FIELDS));
            $entry->setExtraMetadata(array_filter(
                array_map(fn ($v) => is_string($v) ? $v : null, $extra),
                static fn (?string $v) => $v !== null,
            ));

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param array<int, BoardEntry> $entries
     * @return array<int, array<string, mixed>>
     */
    private function dumpTodoEntries(array $entries): array
    {
        $items = [];
        foreach ($entries as $entry) {
            $item = [];
            $item['feature'] = $entry->getFeature();
            if ($entry->getTask() !== null) {
                $item['task'] = $entry->getTask();
            }
            if ($entry->getDeveloper() !== null) {
                $item['developer'] = $entry->getDeveloper();
            }
            if ($entry->getScope() !== null) {
                $item['scope'] = $entry->getScope();
            }
            if ($entry->getType() !== null) {
                $item['type'] = $entry->getType();
            }
            $item['title'] = $entry->getText();
            $body = $this->dumpBodyLines($entry->getExtraLines());
            if ($body !== null) {
                $item['body'] = $body;
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array<int, BoardEntry> $entries
     * @return array<int, array<string, mixed>>
     */
    private function dumpActiveEntries(array $entries): array
    {
        $items = [];
        foreach ($entries as $entry) {
            $ordered = [
                'kind' => $entry->getKind(),
                'stage' => $entry->getStage(),
                'feature' => $entry->getFeature(),
                'task' => $entry->getTask(),
                'developer' => $entry->getDeveloper(),
                'reviewer' => $entry->getReviewer(),
                'branch' => $entry->getBranch(),
                self::FIELD_FEATURE_BRANCH => $entry->getFeatureBranch(),
                'base' => $entry->getBase(),
                'pr' => $entry->getPr(),
            ];

            $item = [];
            foreach ($ordered as $key => $value) {
                if ($value !== null) {
                    $item[$key] = $value;
                }
            }

            if ($entry->checkIsBlocked()) {
                $item['blocked'] = BacklogMetaValue::YES->value;
            }

            if ($entry->getScope() !== null) {
                $item['scope'] = $entry->getScope();
            }

            if ($entry->getType() !== null) {
                $item['type'] = $entry->getType();
            }

            $item['title'] = $entry->getText();

            $body = $this->dumpBodyLines($entry->getExtraLines());
            if ($body !== null) {
                $item['body'] = $body;
            }

            foreach ($entry->getExtraMetadata() as $key => $value) {
                $item[$key] = $value;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Converts a YAML body string to extraLines (with 2-space board prefix added).
     *
     * @return array<string>
     */
    private function loadBodyLines(?string $body): array
    {
        if ($body === null || trim($body) === '') {
            return [];
        }
        $lines = preg_split('/\R/', rtrim($body)) ?: [];

        return array_map(static fn (string $l) => $l !== '' ? '  ' . $l : '', $lines);
    }

    /**
     * Converts extraLines (with 2-space board prefix) to a YAML body string, or null when empty.
     *
     * @param array<string> $extraLines
     */
    private function dumpBodyLines(array $extraLines): ?string
    {
        if ($extraLines === []) {
            return null;
        }
        $stripped = array_map(
            static fn (string $l) => $l !== '' ? substr($l, 2) : '',
            $extraLines,
        );

        return implode("\n", $stripped) . "\n";
    }

    /**
     * Extracts `config.review_resume.enabled` from the raw YAML config block.
     *
     * Returns null when the key is absent (treated as disabled by the notifier).
     *
     * @param mixed $config Raw value from YAML key `config`
     */
    private function loadReviewResumeEnabled(mixed $config): ?bool
    {
        if (!is_array($config)) {
            return null;
        }
        $reviewResume = $config['review_resume'] ?? null;
        if (!is_array($reviewResume)) {
            return null;
        }
        $enabled = $reviewResume['enabled'] ?? null;
        if (!is_bool($enabled)) {
            return null;
        }

        return $enabled;
    }

    /**
     * Builds the `config` block for YAML output, or returns null when all config values are absent.
     *
     * @return array<string, mixed>|null
     */
    private function dumpConfig(BacklogBoard $board): ?array
    {
        $enabled = $board->getReviewResumeEnabled();
        if ($enabled === null) {
            return null;
        }

        return ['review_resume' => ['enabled' => $enabled]];
    }

    private function str(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
