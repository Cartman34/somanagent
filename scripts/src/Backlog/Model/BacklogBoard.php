<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Model;

/**
 * Data container for the local backlog board (DTO).
 */
final class BacklogBoard
{
    public const SECTION_TODO = 'To do';
    public const SECTION_ACTIVE = 'In progress';

    public const STAGE_IN_PROGRESS = 'development';
    public const STAGE_PENDING_REVIEW = 'review';
    public const STAGE_REVIEWING = 'reviewing';
    public const STAGE_REJECTED = 'rejected';
    public const STAGE_APPROVED = 'approved';

    private string $path;

    /** @var array<string, array<BoardEntry>> */
    private array $taskSections = [];

    /**
     * Board-level default for the review-resume notification feature.
     *
     * null  = absent from YAML config (treated as disabled)
     * true  = enabled by board config
     * false = explicitly disabled by board config
     */
    private ?bool $reviewResumeEnabled = null;

    /**
     * @param string $path
     */
    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @param string $section
     * @return array<BoardEntry>
     */
    public function getEntries(string $section): array
    {
        return $this->taskSections[$section] ?? [];
    }

    /**
     * @param string $section
     * @param array<BoardEntry> $entries
     * @return void
     */
    public function setEntries(string $section, array $entries): void
    {
        $this->taskSections[$section] = $entries;
    }

    /**
     * @return ?bool
     */
    public function getReviewResumeEnabled(): ?bool
    {
        return $this->reviewResumeEnabled;
    }

    /**
     * @param ?bool $reviewResumeEnabled
     * @return void
     */
    public function setReviewResumeEnabled(?bool $reviewResumeEnabled): void
    {
        $this->reviewResumeEnabled = $reviewResumeEnabled;
    }
}
