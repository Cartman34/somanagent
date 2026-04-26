<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

use SoManAgent\Script\Backlog\BacklogMetaValue;

/**
 * Represents one top-level backlog item and its optional nested bullet lines.
 */
final class BoardEntry
{
    public const META_AGENT = 'agent';
    public const META_BASE = 'base';
    public const META_BLOCKED = 'blocked';
    public const META_BRANCH = 'branch';
    public const META_FEATURE = 'feature';
    public const META_FEATURE_BRANCH = 'feature-branch';
    public const META_KIND = 'kind';
    public const META_PR = 'pr';
    public const META_STAGE = 'stage';
    public const META_TASK = 'task';
    public const META_TYPE = 'type';

    private const META_BLOCK_PREFIX = '  meta:';
    private const META_LINE_PREFIX = '    ';

    private ?string $agent = null;

    private ?string $base = null;

    private bool $blocked = false;

    private ?string $branch = null;

    private ?string $feature = null;

    private ?string $featureBranch = null;

    private ?string $kind = null;

    private ?string $pr = null;

    private ?string $stage = null;

    private ?string $task = null;

    private ?string $type = null;

    /** @var array<string, string> */
    private array $extraMetadata = [];

    /** @var array<string> */
    private array $extraLines;

    private string $text;

    /**
     * @param array<string> $extraLines
     * @param array<string, string> $metadata
     */
    public function __construct(string $text, array $extraLines = [], array $metadata = [])
    {
        $this->text = $text;
        $this->extraLines = $extraLines;
        $this->importMetadata($metadata);
    }

    /**
     * Builds one entry from raw backlog lines.
     *
     * @param array<string> $lines
     */
    public static function fromLines(array $lines): self
    {
        if ($lines === []) {
            throw new \RuntimeException('Backlog entry cannot be empty.');
        }

        $firstLine = array_shift($lines);
        if (!str_starts_with($firstLine, '- ')) {
            throw new \RuntimeException("Backlog entry must start with '- '.");
        }

        $body = substr($firstLine, 2);
        $metadata = [];

        [$metadata, $body] = self::extractMetadataPrefix($metadata, $body);

        if ($lines !== [] && preg_match('/^\s+\[([a-z0-9_-]+):([^\]]+)\]/', $lines[0]) === 1) {
            $metadataLine = ltrim(array_shift($lines));
            [$metadata, $metadataLine] = self::extractMetadataPrefix($metadata, $metadataLine);
            if (trim($metadataLine) !== '') {
                array_unshift($lines, '  ' . $metadataLine);
            }
        }

        [$lines, $trailingMetadata] = self::extractTrailingMetaBlock($lines);
        foreach ($trailingMetadata as $key => $value) {
            $metadata[$key] = $value;
        }

        return new self(ltrim($body), $lines, $metadata);
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): void
    {
        $this->text = trim($text);
    }

    /**
     * @return array<string>
     */
    public function getExtraLines(): array
    {
        return $this->extraLines;
    }

    /**
     * @param array<string> $lines
     */
    public function setExtraLines(array $lines): void
    {
        $this->extraLines = $lines;
    }

    public function appendExtraLines(array $lines): void
    {
        foreach ($lines as $line) {
            $this->extraLines[] = $line;
        }
    }

    public function getAgent(): ?string
    {
        return $this->agent;
    }

    public function setAgent(?string $agent): void
    {
        $this->agent = self::parseEmptyString($agent);
    }

    public function getBase(): ?string
    {
        return $this->base;
    }

    public function setBase(?string $base): void
    {
        $this->base = self::parseEmptyString($base);
    }

    public function getBranch(): ?string
    {
        return $this->branch;
    }

    public function setBranch(?string $branch): void
    {
        $this->branch = self::parseEmptyString($branch);
    }

    public function getFeature(): ?string
    {
        return $this->feature;
    }

    public function setFeature(?string $feature): void
    {
        $this->feature = self::parseEmptyString($feature);
    }

    public function getFeatureBranch(): ?string
    {
        return $this->featureBranch;
    }

    public function setFeatureBranch(?string $featureBranch): void
    {
        $this->featureBranch = self::parseEmptyString($featureBranch);
    }

    public function getKind(): ?string
    {
        return $this->kind;
    }

    public function setKind(?string $kind): void
    {
        $this->kind = self::parseEmptyString($kind);
    }

    public function getPr(): ?string
    {
        return $this->pr;
    }

    public function setPr(?string $pr): void
    {
        $this->pr = self::parseEmptyString($pr);
    }

    public function getStage(): ?string
    {
        return $this->stage;
    }

    public function setStage(?string $stage): void
    {
        $this->stage = self::parseEmptyString($stage);
    }

    public function getTask(): ?string
    {
        return $this->task;
    }

    public function setTask(?string $task): void
    {
        $this->task = self::parseEmptyString($task);
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = self::parseEmptyString($type);
    }

    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    public function setBlocked(bool $blocked): void
    {
        $this->blocked = $blocked;
    }

    /**
     * @param array<string> $metadataOrder
     * @return array<string>
     */
    public function toLines(array $metadataOrder = []): array
    {
        $metadata = $this->exportMetadata();
        $ordered = [];

        foreach ($metadataOrder as $key) {
            if (isset($metadata[$key])) {
                $ordered[$key] = $metadata[$key];
            }
        }

        foreach ($metadata as $key => $value) {
            if (!isset($ordered[$key])) {
                $ordered[$key] = $value;
            }
        }

        $lines = ['- ' . $this->text];
        foreach ($this->extraLines as $line) {
            $lines[] = $line;
        }

        if ($ordered !== []) {
            $lines[] = self::META_BLOCK_PREFIX;
            foreach ($ordered as $key => $value) {
                $lines[] = sprintf('%s%s: %s', self::META_LINE_PREFIX, $key, $value);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, string> $metadata
     */
    private function importMetadata(array $metadata): void
    {
        $this->agent = self::parseEmptyString($metadata[self::META_AGENT] ?? null);
        $this->base = self::parseEmptyString($metadata[self::META_BASE] ?? null);
        $this->blocked = (self::parseEmptyString($metadata[self::META_BLOCKED] ?? null) === BacklogMetaValue::YES->value);
        $this->branch = self::parseEmptyString($metadata[self::META_BRANCH] ?? null);
        $this->feature = self::parseEmptyString($metadata[self::META_FEATURE] ?? null);
        $this->featureBranch = self::parseEmptyString($metadata[self::META_FEATURE_BRANCH] ?? null);
        $this->kind = self::parseEmptyString($metadata[self::META_KIND] ?? null);
        $this->pr = self::parseEmptyString($metadata[self::META_PR] ?? null);
        $this->stage = self::parseEmptyString($metadata[self::META_STAGE] ?? null);
        $this->task = self::parseEmptyString($metadata[self::META_TASK] ?? null);
        $this->type = self::parseEmptyString($metadata[self::META_TYPE] ?? null);

        $knownKeys = [
            self::META_AGENT, self::META_BASE, self::META_BLOCKED, self::META_BRANCH,
            self::META_FEATURE, self::META_FEATURE_BRANCH, self::META_KIND,
            self::META_PR, self::META_STAGE, self::META_TASK, self::META_TYPE,
        ];

        $this->extraMetadata = array_diff_key($metadata, array_flip($knownKeys));
        // Clean empty values from extra metadata
        $this->extraMetadata = array_filter(
            array_map(self::parseEmptyString(...), $this->extraMetadata),
            static fn(?string $value): bool => $value !== null
        );
    }

    /**
     * @return array<string, string>
     */
    private function exportMetadata(): array
    {
        $metadata = $this->extraMetadata;

        $mappings = [
            self::META_AGENT => $this->agent,
            self::META_BASE => $this->base,
            self::META_BRANCH => $this->branch,
            self::META_FEATURE => $this->feature,
            self::META_FEATURE_BRANCH => $this->featureBranch,
            self::META_KIND => $this->kind,
            self::META_PR => $this->pr,
            self::META_STAGE => $this->stage,
            self::META_TASK => $this->task,
            self::META_TYPE => $this->type,
        ];

        foreach ($mappings as $key => $value) {
            if ($value !== null) {
                $metadata[$key] = $value;
            }
        }

        if ($this->blocked) {
            $metadata[self::META_BLOCKED] = BacklogMetaValue::YES->value;
        }

        return $metadata;
    }

    /**
     * Extracts one metadata prefix chain from the start of the given text.
     *
     * @param array<string, string> $metadata
     * @return array{0: array<string, string>, 1: string}
     */
    private static function extractMetadataPrefix(array $metadata, string $text): array
    {
        while (preg_match('/^\[([a-z0-9_-]+):([^\]]+)\]/', $text, $matches) === 1) {
            $metadata[$matches[1]] = $matches[2];
            $text = substr($text, strlen($matches[0]));
        }

        return [$metadata, ltrim($text)];
    }

    /**
     * Extracts one trailing `meta:` block placed at the end of one entry.
     *
     * @param array<string> $lines
     * @return array{0: array<string>, 1: array<string, string>}
     */
    private static function extractTrailingMetaBlock(array $lines): array
    {
        $metaStartIndex = array_search(self::META_BLOCK_PREFIX, $lines, true);
        if ($metaStartIndex === false) {
            return [$lines, []];
        }

        $metadata = [];
        $metaLines = array_slice($lines, $metaStartIndex + 1);
        if ($metaLines === []) {
            return [$lines, []];
        }

        foreach ($metaLines as $line) {
            if (!str_starts_with($line, self::META_LINE_PREFIX)) {
                return [$lines, []];
            }

            $body = substr($line, strlen(self::META_LINE_PREFIX));
            if (preg_match('/^([a-z0-9_-]+):\s*(.+)$/', $body, $matches) !== 1) {
                return [$lines, []];
            }

            $metadata[$matches[1]] = $matches[2];
        }

        return [array_slice($lines, 0, $metaStartIndex), $metadata];
    }

    public static function parseEmptyString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
