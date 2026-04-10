<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script;

/**
 * Builds stable slugs from free-form text with configurable limits.
 */
final class TextSlugger
{
    private int $maxWords;

    private int $maxLength;

    private string $separator;

    public function __construct(
        int $maxWords = 8,
        int $maxLength = 64,
        string $separator = '-',
    ) {
        $this->maxWords = $maxWords;
        $this->maxLength = $maxLength;
        $this->separator = $separator;
    }

    public function slugify(string $text): string
    {
        $text = $this->extractRelevantLabel($text);
        $text = $this->transliterate($text);
        $text = mb_strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', $this->separator, $text) ?? '';
        $text = trim($text, $this->separator);

        if ($text === '') {
            throw new \RuntimeException('Unable to derive a valid feature slug.');
        }

        $words = array_values(array_filter(explode($this->separator, $text)));
        $words = array_slice($words, 0, $this->maxWords);

        if ($words === []) {
            throw new \RuntimeException('Unable to derive a valid feature slug.');
        }

        $slug = implode($this->separator, $words);

        if (strlen($slug) > $this->maxLength) {
            $slug = substr($slug, 0, $this->maxLength);
            $slug = trim($slug, $this->separator);
            $lastSeparator = strrpos($slug, $this->separator);

            if ($lastSeparator !== false) {
                $slug = substr($slug, 0, $lastSeparator);
            }

            $slug = trim($slug, $this->separator);
        }

        if ($slug === '') {
            throw new \RuntimeException('Unable to derive a valid feature slug.');
        }

        return $slug;
    }

    private function extractRelevantLabel(string $text): string
    {
        $text = trim($text);
        $segments = preg_split('/[:;\.\(\[]/', $text) ?: [];
        $label = trim($segments[0] ?? $text);

        return $label !== '' ? $label : $text;
    }

    private function transliterate(string $text): string
    {
        $transliterated = function_exists('iconv')
            ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text)
            : false;

        if ($transliterated === false) {
            return $text;
        }

        return $transliterated;
    }
}
