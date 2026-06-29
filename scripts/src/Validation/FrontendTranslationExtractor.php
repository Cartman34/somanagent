<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Validation;

use Sowapps\Toolkit\Symfony\Translation\AbstractUsageExtractor;
use Sowapps\Toolkit\Symfony\Translation\TranslationUsage;

/**
 * Extracts translation-key usages from the SoManAgent frontend sources (`.ts`/`.tsx`).
 *
 * Covers the project's i18n conventions: direct `t()/tt()/tc()` calls, key constants declared as
 * arrays (`*_KEYS`) or objects (`*_LABEL_KEYS`/`*_KEY_MAP`/`*_TRANSLATION_KEYS`), structured
 * `key: '<key>'` usages, and forbidden dynamic key construction via template literals.
 */
final class FrontendTranslationExtractor extends AbstractUsageExtractor
{
    private const KEY_PATTERN = '[a-z_][a-zA-Z0-9_]*(?:\.[a-zA-Z0-9_]+)+';

    public function extract(string $content, string $relativePath): TranslationUsage
    {
        $used = [];
        $used = $this->mergeOccurrences($used, $this->directCallKeys($content));
        $used = $this->mergeOccurrences($used, $this->staticArrayKeys($content));
        $used = $this->mergeOccurrences($used, $this->staticObjectValueKeys($content));
        $used = $this->mergeOccurrences($used, $this->structuredKeyUsages($content));

        return new TranslationUsage($used, $this->dynamicViolations($content, $relativePath));
    }

    /**
     * @return array<string, list<int>>
     */
    private function directCallKeys(string $content): array
    {
        return $this->matchKeyOccurrences('/\b(?:t|tt|tc)\(\s*([\'"])(?<key>' . self::KEY_PATTERN . ')\1/u', $content);
    }

    /**
     * @return array<string, list<int>>
     */
    private function structuredKeyUsages(string $content): array
    {
        return $this->matchKeyOccurrences('/\bkey\s*:\s*[\'"](?<key>' . self::KEY_PATTERN . ')[\'"]/u', $content);
    }

    /**
     * @return array<string, list<int>>
     */
    private function staticArrayKeys(string $content): array
    {
        $result = [];
        preg_match_all('/const\s+[A-Z][A-Z0-9_]*_(?:TRANSLATION_KEYS|KEYS)\b[^=]*=\s*\[/u', $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $match) {
            [$declaration, $offset] = $match;
            $openBracket = strpos($declaration, '[');
            if ($openBracket === false) {
                continue;
            }
            $body = $this->extractBracketBlock($content, (int) $offset + $openBracket, '[', ']');
            if ($body === null) {
                continue;
            }

            preg_match_all('/([\'"])(?<key>' . self::KEY_PATTERN . ')\1/u', $body['content'], $keyMatches, PREG_OFFSET_CAPTURE);
            foreach ($keyMatches['key'] as $keyMatch) {
                [$key, $keyOffset] = $keyMatch;
                $result[$key][] = $this->lineNumberForOffset($content, $body['start'] + (int) $keyOffset);
            }
        }

        return $result;
    }

    /**
     * @return array<string, list<int>>
     */
    private function staticObjectValueKeys(string $content): array
    {
        $result = [];
        preg_match_all('/const\s+[A-Z][A-Z0-9_]*_(?:LABEL_KEYS|KEY_MAP|TRANSLATION_KEYS)\b[^=]*=\s*\{/u', $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $match) {
            [$declaration, $offset] = $match;
            $openBrace = strpos($declaration, '{');
            if ($openBrace === false) {
                continue;
            }
            $body = $this->extractBracketBlock($content, (int) $offset + $openBrace, '{', '}');
            if ($body === null) {
                continue;
            }

            preg_match_all('/:\s*([\'"])(?<key>' . self::KEY_PATTERN . ')\1/u', $body['content'], $valueMatches, PREG_OFFSET_CAPTURE);
            foreach ($valueMatches['key'] as $valueMatch) {
                [$key, $keyOffset] = $valueMatch;
                $result[$key][] = $this->lineNumberForOffset($content, $body['start'] + (int) $keyOffset);
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function dynamicViolations(string $content, string $path): array
    {
        $violations = [];
        preg_match_all('/\b(?:t|tt|tc)\(\s*`[^`]*\$\{[^`]*`\s*\)/u', $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $match) {
            [$expression, $offset] = $match;
            $line = $this->lineNumberForOffset($content, (int) $offset);
            $violations[] = sprintf('%s:%d  %s', $path, $line, trim($expression));
        }

        return $violations;
    }
}
