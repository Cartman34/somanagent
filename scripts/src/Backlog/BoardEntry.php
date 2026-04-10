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

        while (preg_match('/^\[([a-z0-9_-]+):([^\]]+)\]/', $body, $matches) === 1) {
            $metadata[$matches[1]] = $matches[2];
            $body = substr($body, strlen($matches[0]));
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
        $metadata = '';
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

        foreach ($ordered as $key => $value) {
            $metadata .= sprintf('[%s:%s]', $key, $value);
        }

        return array_merge(
            ['- ' . $metadata . $this->text],
            $this->extraLines,
        );
    }
}
