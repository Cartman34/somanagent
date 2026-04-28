<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Model;

/**
 * Represents one top-level backlog item and its metadata (DTO).
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
     */
    public function __construct(string $text, array $extraLines = [])
    {
        $this->text = trim($text);
        $this->extraLines = $extraLines;
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
        $this->agent = $agent;
    }

    public function getBase(): ?string
    {
        return $this->base;
    }

    public function setBase(?string $base): void
    {
        $this->base = $base;
    }

    public function checkIsBlocked(): bool
    {
        return $this->blocked;
    }

    public function setBlocked(bool $blocked): void
    {
        $this->blocked = $blocked;
    }

    public function getBranch(): ?string
    {
        return $this->branch;
    }

    public function setBranch(?string $branch): void
    {
        $this->branch = $branch;
    }

    public function getFeature(): ?string
    {
        return $this->feature;
    }

    public function setFeature(?string $feature): void
    {
        $this->feature = $feature;
    }

    public function getFeatureBranch(): ?string
    {
        return $this->featureBranch;
    }

    public function setFeatureBranch(?string $featureBranch): void
    {
        $this->featureBranch = $featureBranch;
    }

    public function getKind(): ?string
    {
        return $this->kind;
    }

    public function setKind(?string $kind): void
    {
        $this->kind = $kind;
    }

    public function getPr(): ?string
    {
        return $this->pr;
    }

    public function setPr(?string $pr): void
    {
        $this->pr = $pr;
    }

    public function getStage(): ?string
    {
        return $this->stage;
    }

    public function setStage(?string $stage): void
    {
        $this->stage = $stage;
    }

    public function getTask(): ?string
    {
        return $this->task;
    }

    public function setTask(?string $task): void
    {
        $this->task = $task;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = $type;
    }

    /**
     * @return array<string, string>
     */
    public function getExtraMetadata(): array
    {
        return $this->extraMetadata;
    }

    /**
     * @param array<string, string> $extraMetadata
     */
    public function setExtraMetadata(array $extraMetadata): void
    {
        $this->extraMetadata = $extraMetadata;
    }

    /**
     * @deprecated Use BacklogBoardService::sanitizeString
     */
    public static function parseEmptyString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
