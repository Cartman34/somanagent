<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Validation;

use Sowapps\Toolkit\Symfony\Translation\AbstractUsageExtractor;
use Sowapps\Toolkit\Symfony\Translation\TranslationUsage;

/**
 * Extracts translation-key usages from the SoManAgent backend sources (`.php`).
 *
 * Covers the project's i18n conventions: `->trans()`/`trans()` calls, structured `logs` domain
 * usages, the API error factory (`->create()`/`->createForField()`), key constants declared as
 * arrays (`*_KEYS`/`*_LABEL_KEYS`/`*_TRANSLATION_KEYS`), and validation `'code' => '<key>'` entries.
 */
final class BackendTranslationExtractor extends AbstractUsageExtractor
{
    private const KEY_PATTERN = '[a-z_][a-zA-Z0-9_]*(?:\.[a-zA-Z0-9_]+)+';

    public function extract(string $content, string $relativePath): TranslationUsage
    {
        $used = [];
        $used = $this->mergeOccurrences($used, $this->translationCallKeys($content));
        $used = $this->mergeOccurrences($used, $this->structuredKeyUsages($content));
        $used = $this->mergeOccurrences($used, $this->apiErrorFactoryKeys($content));
        $used = $this->mergeOccurrences($used, $this->staticArrayValueKeys($content));
        $used = $this->mergeOccurrences($used, $this->validationCodeKeys($content));

        return new TranslationUsage($used);
    }

    /**
     * @return array<string, list<int>>
     */
    private function translationCallKeys(string $content): array
    {
        return $this->matchKeyOccurrences('/(?:->trans|\btrans)\(\s*([\'"])(?<key>' . self::KEY_PATTERN . ')\1/u', $content);
    }

    /**
     * @return array<string, list<int>>
     */
    private function structuredKeyUsages(string $content): array
    {
        return $this->matchKeyOccurrences(
            '/[\'"]domain[\'"]\s*=>\s*[\'"]logs[\'"][^]]*?[\'"]key[\'"]\s*=>\s*[\'"](?<key>logs\.[a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+)+)[\'"]/us',
            $content,
        );
    }

    /**
     * @return array<string, list<int>>
     */
    private function validationCodeKeys(string $content): array
    {
        return $this->matchKeyOccurrences('/[\'"]code[\'"]\s*=>\s*[\'"](?<key>' . self::KEY_PATTERN . ')[\'"]/', $content);
    }

    /**
     * @return array<string, list<int>>
     */
    private function apiErrorFactoryKeys(string $content): array
    {
        $result = [];
        preg_match_all(
            '/->create\(\s*[\'"](?<key>' . self::KEY_PATTERN . ')[\'"]|->createForField\(\s*[^,]+,\s*[\'"](?<field_key>' . self::KEY_PATTERN . ')[\'"]/u',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        foreach (['key', 'field_key'] as $group) {
            foreach ($matches[$group] as $match) {
                [$key, $offset] = $match;
                if ($key === '') {
                    continue;
                }
                $result[$key][] = $this->lineNumberForOffset($content, (int) $offset);
            }
        }

        return $result;
    }

    /**
     * @return array<string, list<int>>
     */
    private function staticArrayValueKeys(string $content): array
    {
        $result = [];
        preg_match_all('/(?:private|public|protected)?\s*const\s+[A-Z][A-Z0-9_]*_(?:TRANSLATION_KEYS|KEYS|LABEL_KEYS)\b[^=]*=\s*\[/u', $content, $matches, PREG_OFFSET_CAPTURE);

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

            preg_match_all('/=>\s*[\'"](?<key>' . self::KEY_PATTERN . ')[\'"]/u', $body['content'], $keyMatches, PREG_OFFSET_CAPTURE);
            foreach ($keyMatches['key'] as $keyMatch) {
                [$key, $keyOffset] = $keyMatch;
                $result[$key][] = $this->lineNumberForOffset($content, $body['start'] + (int) $keyOffset);
            }
        }

        return $result;
    }
}
