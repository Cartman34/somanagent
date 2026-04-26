<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

/**
 * Normalizes reviewer body files before they are stored in the backlog review file.
 */
final class BacklogReviewBodyFormatter
{
    private const DEFAULT_EMPTY_REVIEW_ITEM = '1. No details provided.';

    /**
     * Reads a review body file and returns normalized numbered review items.
     *
     * @return array<string>
     */
    public function fromFile(string $bodyFile): array
    {
        return $this->fromString((string) file_get_contents($bodyFile));
    }

    /**
     * Converts free-form review body text to a clean numbered finding list.
     *
     * @return array<string>
     */
    public function fromString(string $contents): array
    {
        $contents = trim($contents);
        if ($contents === '') {
            return [self::DEFAULT_EMPTY_REVIEW_ITEM];
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $contents) ?: [])));
        $items = [];

        foreach ($lines as $line) {
            $item = preg_replace('/^\d+\.\s+/', '', $line);
            $item = preg_replace('/^[-*]\s+/', '', is_string($item) ? $item : $line);
            $item = trim(is_string($item) ? $item : $line);
            if ($item === '') {
                continue;
            }
            if (preg_match('/^#{1,6}\s+/', $item) === 1) {
                throw new \RuntimeException('Review body items must be plain findings. Remove Markdown headings from --body-file.');
            }

            $items[] = sprintf('%d. %s', count($items) + 1, $item);
        }

        return $items !== [] ? $items : [self::DEFAULT_EMPTY_REVIEW_ITEM];
    }
}
