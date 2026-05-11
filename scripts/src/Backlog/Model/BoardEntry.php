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
    public const META_REVIEWER = 'reviewer';
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

    private ?string $reviewer = null;

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

    /**
     * @return string
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * @param string $text
     * @return void
     */
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
     * @return void
     */
    public function setExtraLines(array $lines): void
    {
        $this->extraLines = $lines;
    }

    /**
     * @param array<string> $lines
     * @return void
     */
    public function appendExtraLines(array $lines): void
    {
        foreach ($lines as $line) {
            $this->extraLines[] = $line;
        }
    }

    /**
     * @return ?string
     */
    public function getAgent(): ?string
    {
        return $this->agent;
    }

    /**
     * @param ?string $agent
     * @return void
     */
    public function setAgent(?string $agent): void
    {
        $this->agent = $agent;
    }

    /**
     * @return ?string
     */
    public function getBase(): ?string
    {
        return $this->base;
    }

    /**
     * @param ?string $base
     * @return void
     */
    public function setBase(?string $base): void
    {
        $this->base = $base;
    }

    /**
     * @return bool
     */
    public function checkIsBlocked(): bool
    {
        return $this->blocked;
    }

    /**
     * @param bool $blocked
     * @return void
     */
    public function setBlocked(bool $blocked): void
    {
        $this->blocked = $blocked;
    }

    /**
     * @return ?string
     */
    public function getBranch(): ?string
    {
        return $this->branch;
    }

    /**
     * @param ?string $branch
     * @return void
     */
    public function setBranch(?string $branch): void
    {
        $this->branch = $branch;
    }

    /**
     * @return ?string
     */
    public function getFeature(): ?string
    {
        return $this->feature;
    }

    /**
     * @param ?string $feature
     * @return void
     */
    public function setFeature(?string $feature): void
    {
        $this->feature = $feature;
    }

    /**
     * @return ?string
     */
    public function getFeatureBranch(): ?string
    {
        return $this->featureBranch;
    }

    /**
     * @param ?string $featureBranch
     * @return void
     */
    public function setFeatureBranch(?string $featureBranch): void
    {
        $this->featureBranch = $featureBranch;
    }

    /**
     * @return ?string
     */
    public function getKind(): ?string
    {
        return $this->kind;
    }

    /**
     * @param ?string $kind
     * @return void
     */
    public function setKind(?string $kind): void
    {
        $this->kind = $kind;
    }

    /**
     * @return ?string
     */
    public function getPr(): ?string
    {
        return $this->pr;
    }

    /**
     * @param ?string $pr
     * @return void
     */
    public function setPr(?string $pr): void
    {
        $this->pr = $pr;
    }

    /**
     * @return ?string
     */
    public function getReviewer(): ?string
    {
        return $this->reviewer;
    }

    /**
     * @param ?string $reviewer
     * @return void
     */
    public function setReviewer(?string $reviewer): void
    {
        $this->reviewer = $reviewer;
    }

    /**
     * @return ?string
     */
    public function getStage(): ?string
    {
        return $this->stage;
    }

    /**
     * @param ?string $stage
     * @return void
     */
    public function setStage(?string $stage): void
    {
        $this->stage = $stage;
    }

    /**
     * @return ?string
     */
    public function getTask(): ?string
    {
        return $this->task;
    }

    /**
     * @param ?string $task
     * @return void
     */
    public function setTask(?string $task): void
    {
        $this->task = $task;
    }

    /**
     * @return ?string
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * @param ?string $type
     * @return void
     */
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
     * @return void
     */
    public function setExtraMetadata(array $extraMetadata): void
    {
        $this->extraMetadata = $extraMetadata;
    }

    /**
     * @deprecated Use BacklogBoardService::sanitizeString
     * @param ?string $value
     * @return ?string
     */
    public static function parseEmptyString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
