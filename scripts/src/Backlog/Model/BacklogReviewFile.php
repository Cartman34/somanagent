<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Model;

/**
 * Data container for reviewer findings in the local review file (DTO).
 */
final class BacklogReviewFile
{
    public const SECTION_RULES = "Règles d'usage";
    public const SECTION_CURRENT_REVIEW = "Revue en cours";
    public const EMPTY_REVIEW_TEXT = 'Aucune review en cours.';

    private string $path;

    private string $header;

    /** @var array<string, array<string>> */
    private array $sections = [];

    /** @var array<string, array<string>> */
    private array $reviews = [];

    public function __construct(string $path, string $header)
    {
        $this->path = $path;
        $this->header = $header;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHeader(): string
    {
        return $this->header;
    }

    public function setHeader(string $header): void
    {
        $this->header = $header;
    }

    /**
     * @return array<string, array<string>>
     */
    public function getSections(): array
    {
        return $this->sections;
    }

    /**
     * @param array<string, array<string>> $sections
     */
    public function setSections(array $sections): void
    {
        $this->sections = $sections;
    }

    /**
     * @return array<string, array<string>>
     */
    public function getReviews(): array
    {
        return $this->reviews;
    }

    /**
     * @param array<string, array<string>> $reviews
     */
    public function setReviews(array $reviews): void
    {
        $this->reviews = $reviews;
    }

    /**
     * @param array<string> $items
     */
    public function setReview(string $key, array $items): void
    {
        $this->reviews[$key] = $items;
    }

    public function clearReview(string $key): void
    {
        unset($this->reviews[$key]);
    }
}
