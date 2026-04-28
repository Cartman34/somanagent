<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Model;

/**
 * Data container for the local backlog board (DTO).
 */
final class BacklogBoard
{
    public const SECTION_TODO = "À faire";
    public const SECTION_ACTIVE = 'Traitement en cours';

    public const STAGE_IN_PROGRESS = 'development';
    public const STAGE_IN_REVIEW = 'review';
    public const STAGE_REJECTED = 'rejected';
    public const STAGE_APPROVED = 'approved';

    private string $path;

    private string $title;

    /** @var array<int, string> */
    private array $sectionOrder = [];

    /** @var array<string, array<string>> */
    private array $rawSections = [];

    /** @var array<string, array<BoardEntry>> */
    private array $taskSections = [];

    public function __construct(string $path, string $title)
    {
        $this->path = $path;
        $this->title = $title;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    /**
     * @return array<int, string>
     */
    public function getSectionOrder(): array
    {
        return $this->sectionOrder;
    }

    /**
     * @param array<int, string> $sectionOrder
     */
    public function setSectionOrder(array $sectionOrder): void
    {
        $this->sectionOrder = $sectionOrder;
    }

    /**
     * @return array<string, array<string>>
     */
    public function getRawSections(): array
    {
        return $this->rawSections;
    }

    /**
     * @param array<string, array<string>> $rawSections
     */
    public function setRawSections(array $rawSections): void
    {
        $this->rawSections = $rawSections;
    }

    /**
     * @return array<BoardEntry>
     */
    public function getEntries(string $section): array
    {
        return $this->taskSections[$section] ?? [];
    }

    /**
     * @param array<BoardEntry> $entries
     */
    public function setEntries(string $section, array $entries): void
    {
        $this->taskSections[$section] = $entries;
    }
}
