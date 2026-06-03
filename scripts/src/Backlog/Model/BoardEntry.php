<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Model;

/**
 * Represents one top-level backlog item and its metadata (DTO).
 */
final class BoardEntry
{
    public const META_DEVELOPER = 'developer';
    public const META_BASE = 'base';
    public const META_BLOCKED = 'blocked';
    public const META_BRANCH = 'branch';
    public const META_FEATURE = 'feature';
    public const META_FEATURE_BRANCH = 'feature-branch';
    public const META_KIND = 'kind';
    public const META_PR = 'pr';
    public const META_REVIEWER = 'reviewer';
    public const META_SCOPE = 'scope';
    public const META_STAGE = 'stage';
    public const META_TASK = 'task';
    public const META_TYPE = 'type';

    /**
     * Developer agent code currently assigned to the entry, or null when the entry is unassigned.
     */
    private ?string $developer = null;

    /**
     * Recorded Git base commit used to scope reviews and rebases for the entry branch.
     */
    private ?string $base = null;

    /**
     * Whether the entry is blocked in the backlog workflow.
     */
    private bool $blocked = false;

    /**
     * Git branch that carries the entry implementation.
     */
    private ?string $branch = null;

    /**
     * Canonical parent feature slug for the entry.
     */
    private ?string $feature = null;

    /**
     * Parent feature branch used by child task entries.
     */
    private ?string $featureBranch = null;

    /**
     * Backlog entry kind, usually feature or task.
     */
    private ?string $kind = null;

    /**
     * Pull request identifier for feature review flow, or none/null when absent.
     */
    private ?string $pr = null;

    /**
     * Reviewer agent code currently reviewing the entry, or null when no review is claimed.
     */
    private ?string $reviewer = null;

    /**
     * Workflow stage for the entry, such as development, review, rejected, or approved.
     */
    private ?string $stage = null;

    /**
     * Child task slug when the entry is a scoped task.
     */
    private ?string $task = null;

    /**
     * Named scope restricting the files this entry may touch, or null when the entry is unrestricted (ALL).
     */
    private ?string $scope = null;

    /**
     * Branch/task type recorded for the entry, such as feat, fix, or tech.
     */
    private ?string $type = null;

    /**
     * Additional metadata keys preserved when parsing and formatting the board.
     *
     * @var array<string, string>
     */
    private array $extraMetadata = [];

    /**
     * Body lines below the main entry title, excluding the trailing metadata block.
     *
     * @var array<string>
     */
    private array $extraLines;

    /**
     * Human-readable entry title shown on the backlog line.
     */
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
     * @return ?string
     */
    public function getDeveloper(): ?string
    {
        return $this->developer;
    }

    /**
     * @param ?string $developer
     * @return void
     */
    public function setDeveloper(?string $developer): void
    {
        $developer = trim((string) $developer);
        $this->developer = $developer === '' || $developer === 'none' ? null : $developer;
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
    public function getScope(): ?string
    {
        return $this->scope;
    }

    /**
     * @param ?string $scope
     * @return void
     */
    public function setScope(?string $scope): void
    {
        $this->scope = $scope === '' ? null : $scope;
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

}
