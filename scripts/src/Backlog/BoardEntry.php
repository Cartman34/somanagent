<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

/**
 * Represents one top-level backlog item and its optional nested bullet lines.
 */
final class BoardEntry
{
    private const META_BLOCK_PREFIX = '  meta:';
    private const META_LINE_PREFIX = '    ';

    /** @var array<string, string> */
    private array $metadata = [];

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
        $this->metadata = $metadata;
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

    public function getMeta(string $key): ?string
    {
        return $this->metadata[$key] ?? null;
    }

    public function setMeta(string $key, string $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function unsetMeta(string $key): void
    {
        unset($this->metadata[$key]);
    }

    public function hasMeta(string $key): bool
    {
        return array_key_exists($key, $this->metadata);
    }

    /**
     * @return array<string, string>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param array<string> $metadataOrder
     * @return array<string>
     */
    public function toLines(array $metadataOrder = []): array
    {
        $ordered = [];

        foreach ($metadataOrder as $key) {
            if (isset($this->metadata[$key])) {
                $ordered[$key] = $this->metadata[$key];
            }
        }

        foreach ($this->metadata as $key => $value) {
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
}
