<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Service;

/**
 * Reads reviewer body files and stores each line verbatim in the backlog review file.
 */
final class BacklogReviewBodyFormatter
{
    private const DEFAULT_EMPTY_REVIEW_ITEM = 'No details provided.';

    /**
     * Reads a review body file and returns each non-empty line verbatim.
     *
     * @return array<string>
     */
    public function fromFile(string $bodyFile): array
    {
        return $this->fromString((string) file_get_contents($bodyFile));
    }

    /**
     * Splits review body text into verbatim lines, rejecting Markdown headings.
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
            if (preg_match('/^#{1,6}\s/', $line) === 1) {
                throw new \RuntimeException('Review body items must be plain findings. Remove Markdown headings from --body-file.');
            }
            $items[] = $line;
        }

        return $items !== [] ? $items : [self::DEFAULT_EMPTY_REVIEW_ITEM];
    }
}
