<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates translation key declarations and source usages.
 *
 * Checks:
 * - missing: used keys that are absent from translation files
 * - unused: declared keys that are never referenced in source
 * - dynamic: forbidden dynamic translation key construction
 */
final class ValidateTranslationsRunner extends AbstractScriptRunner
{
    /**
     * @var array<string>
     */
    private const AVAILABLE_CHECKS = ['missing', 'unused', 'dynamic'];
    private const KEY_PATTERN = '[a-z_][a-zA-Z0-9_]*(?:\.[a-zA-Z0-9_]+)+';

    protected function getDescription(): string
    {
        return 'Validate translation key usage across source files';
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--check=<name[,name...]>', 'description' => 'Checks to run: missing, unused, dynamic. Repeatable and combinable.'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/validate-translations.php',
            'php scripts/validate-translations.php --check=missing',
            'php scripts/validate-translations.php --check=missing --check=dynamic',
            'php scripts/validate-translations.php --check=missing,unused,dynamic',
        ];
    }

    public function run(array $args): int
    {
        require_once $this->projectRoot . '/backend/vendor/autoload.php';

        $checks = $this->parseChecks($args);
        if ($checks === null) {
            return 1;
        }

        $declaredKeys = $this->collectDeclaredKeys();
        $usage = $this->collectUsedKeysAndViolations();

        $hasFailure = false;

        if (in_array('missing', $checks, true)) {
            echo "=== Missing translation keys ===\n";
            $missing = $this->buildMissingKeysReport($declaredKeys, $usage['used_by_key']);
            if ($missing === []) {
                echo "(none)\n";
            } else {
                $hasFailure = true;
                foreach ($missing as $line) {
                    echo $line . "\n";
                }
            }
            echo "\n";
        }

        if (in_array('unused', $checks, true)) {
            echo "=== Unused translation keys ===\n";
            $unused = $this->buildUnusedKeysReport($declaredKeys, $usage['used_keys']);
            if ($unused === []) {
                echo "(none)\n";
            } else {
                $hasFailure = true;
                foreach ($unused as $line) {
                    echo $line . "\n";
                }
            }
            echo "\n";
        }

        if (in_array('dynamic', $checks, true)) {
            echo "=== Dynamic translation key usage ===\n";
            $dynamicViolations = $usage['dynamic_violations'];
            if ($dynamicViolations === []) {
                echo "(none)\n";
            } else {
                $hasFailure = true;
                foreach ($dynamicViolations as $line) {
                    echo $line . "\n";
                }
            }
            echo "\n";
        }

        return $hasFailure ? 1 : 0;
    }

    /**
     * @param array<string> $args
     * @return array<string>|null
     */
    private function parseChecks(array $args): ?array
    {
        $checks = [];

        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--check=')) {
                fwrite(STDERR, "Unknown option: {$arg}\n");
                return null;
            }

            $rawValues = explode(',', substr($arg, strlen('--check=')));
            foreach ($rawValues as $rawValue) {
                $check = trim($rawValue);
                if ($check === '') {
                    continue;
                }
                if (!in_array($check, self::AVAILABLE_CHECKS, true)) {
                    fwrite(STDERR, "Unknown check: {$check}\n");
                    return null;
                }
                $checks[$check] = true;
            }
        }

        return $checks === [] ? self::AVAILABLE_CHECKS : array_keys($checks);
    }

    /**
     * @return array<string, array{file: string, domain: string}>
     */
    private function collectDeclaredKeys(): array
    {
        $declaredKeys = [];
        $finder = new Finder();
        $finder->files()->in($this->projectRoot . '/backend/translations')->name('*.fr.yaml')->sortByName();

        foreach ($finder as $file) {
            $path = str_replace($this->projectRoot . '/', '', $file->getRealPath() ?: $file->getPathname());
            $domain = preg_replace('/\.fr\.yaml$/', '', $file->getBasename()) ?? $file->getBasename();
            $rawContent = file_get_contents($file->getPathname());
            if ($rawContent === false) {
                continue;
            }

            $normalizedContent = preg_replace(
                '/^(\s*)(true|false|yes|no|on|off|null)(\s*:)/mi',
                '$1"$2"$3',
                $rawContent,
            );

            $parsed = Yaml::parse($normalizedContent ?? $rawContent);
            if (!is_array($parsed)) {
                continue;
            }

            foreach ($this->flattenTranslationKeys($parsed) as $key) {
                $declaredKeys[$key] = [
                    'file' => $path,
                    'domain' => $domain,
                ];
            }
        }

        ksort($declaredKeys);

        return $declaredKeys;
    }

    /**
     * @param array<mixed> $data
     * @return array<string>
     */
    private function flattenTranslationKeys(array $data, string $prefix = ''): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $fullKey = $prefix === '' ? $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $keys = [...$keys, ...$this->flattenTranslationKeys($value, $fullKey)];
                continue;
            }

            $keys[] = $fullKey;
        }

        return $keys;
    }

    /**
     * @return array{
     *   used_keys: array<string>,
     *   used_by_key: array<string, array<string>>,
     *   dynamic_violations: array<string>
     * }
     */
    private function collectUsedKeysAndViolations(): array
    {
        $usedByKey = [];
        $dynamicViolations = [];

        $frontendFinder = new Finder();
        $frontendFinder->files()->in($this->projectRoot . '/frontend/src')->name(['*.ts', '*.tsx'])->sortByName();
        foreach ($frontendFinder as $file) {
            $path = str_replace($this->projectRoot . '/', '', $file->getRealPath() ?: $file->getPathname());
            $content = $file->getContents();

            foreach ($this->extractFrontendDirectCallKeys($content) as $key => $lines) {
                foreach ($lines as $line) {
                    $usedByKey[$key][] = "{$path}:{$line}";
                }
            }

            foreach ($this->extractStaticArrayKeys($content) as $key => $lines) {
                foreach ($lines as $line) {
                    $usedByKey[$key][] = "{$path}:{$line}";
                }
            }

            foreach ($this->extractStaticObjectValueKeys($content) as $key => $lines) {
                foreach ($lines as $line) {
                    $usedByKey[$key][] = "{$path}:{$line}";
                }
            }

            foreach ($this->extractFrontendStructuredKeyUsages($content) as $key => $lines) {
                foreach ($lines as $line) {
                    $usedByKey[$key][] = "{$path}:{$line}";
                }
            }

            foreach ($this->extractDynamicFrontendViolations($content, $path) as $violation) {
                $dynamicViolations[] = $violation;
            }
        }

        $backendFinder = new Finder();
        $backendFinder->files()->in($this->projectRoot . '/backend/src')->name('*.php')->sortByName();
        foreach ($backendFinder as $file) {
            $path = str_replace($this->projectRoot . '/', '', $file->getRealPath() ?: $file->getPathname());
            $content = $file->getContents();

            foreach ($this->extractPhpTranslationCallKeys($content) as $key => $lines) {
                foreach ($lines as $line) {
                    $usedByKey[$key][] = "{$path}:{$line}";
                }
            }

            foreach ($this->extractPhpStructuredKeyUsages($content) as $key => $lines) {
                foreach ($lines as $line) {
                    $usedByKey[$key][] = "{$path}:{$line}";
                }
            }

            foreach ($this->extractPhpApiErrorFactoryKeys($content) as $key => $lines) {
                foreach ($lines as $line) {
                    $usedByKey[$key][] = "{$path}:{$line}";
                }
            }

            foreach ($this->extractPhpStaticArrayValueKeys($content) as $key => $lines) {
                foreach ($lines as $line) {
                    $usedByKey[$key][] = "{$path}:{$line}";
                }
            }
        }

        foreach ($usedByKey as $key => $references) {
            $usedByKey[$key] = array_values(array_unique($references));
            sort($usedByKey[$key]);
        }

        ksort($usedByKey);
        sort($dynamicViolations);

        return [
            'used_keys' => array_keys($usedByKey),
            'used_by_key' => $usedByKey,
            'dynamic_violations' => array_values(array_unique($dynamicViolations)),
        ];
    }

    /**
     * @return array<string, array<int>>
     */
    private function extractFrontendDirectCallKeys(string $content): array
    {
        $matches = [];
        preg_match_all('/\b(?:t|tt|tc)\(\s*([\'"])(?<key>' . self::KEY_PATTERN . ')\1/u', $content, $allMatches, PREG_OFFSET_CAPTURE);

        $result = [];
        foreach ($allMatches['key'] ?? [] as $match) {
            [$key, $offset] = $match;
            $result[$key][] = $this->lineNumberForOffset($content, $offset);
        }

        return $result;
    }

    /**
     * @return array<string, array<int>>
     */
    private function extractPhpTranslationCallKeys(string $content): array
    {
        preg_match_all('/(?:->trans|\btrans)\(\s*([\'"])(?<key>' . self::KEY_PATTERN . ')\1/u', $content, $matches, PREG_OFFSET_CAPTURE);

        $result = [];
        foreach ($matches['key'] ?? [] as $match) {
            [$key, $offset] = $match;
            $result[$key][] = $this->lineNumberForOffset($content, $offset);
        }

        return $result;
    }

    /**
     * @return array<string, array<int>>
     */
    private function extractPhpStructuredKeyUsages(string $content): array
    {
        preg_match_all(
            '/[\'"]domain[\'"]\s*=>\s*[\'"]logs[\'"][^]]*?[\'"]key[\'"]\s*=>\s*[\'"](?<key>logs\.[a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+)+)[\'"]/us',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $result = [];
        foreach ($matches['key'] ?? [] as $match) {
            [$key, $offset] = $match;
            $result[$key][] = $this->lineNumberForOffset($content, $offset);
        }

        return $result;
    }

    /**
     * @return array<string, array<int>>
     */
    private function extractPhpApiErrorFactoryKeys(string $content): array
    {
        $result = [];

        preg_match_all(
            '/->create\(\s*[\'"](?<key>' . self::KEY_PATTERN . ')[\'"]|->createForField\(\s*[^,]+,\s*[\'"](?<field_key>' . self::KEY_PATTERN . ')[\'"]/u',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        foreach (['key', 'field_key'] as $group) {
            foreach ($matches[$group] ?? [] as $match) {
                [$key, $offset] = $match;
                if ($key === '') {
                    continue;
                }

                $result[$key][] = $this->lineNumberForOffset($content, $offset);
            }
        }

        return $result;
    }

    /**
     * @return array<string, array<int>>
     */
    private function extractStaticArrayKeys(string $content): array
    {
        $result = [];
        preg_match_all('/const\s+[A-Z][A-Z0-9_]*_(?:TRANSLATION_KEYS|KEYS)\b[^=]*=\s*\[/u', $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] ?? [] as $match) {
            [$declaration, $offset] = $match;
            $openBracket = strpos($declaration, '[');
            if ($openBracket === false) {
                continue;
            }

            $bodyStart = $offset + $openBracket;
            $body = $this->extractBracketBlock($content, $bodyStart, '[', ']');
            if ($body === null) {
                continue;
            }

            preg_match_all('/([\'"])(?<key>' . self::KEY_PATTERN . ')\1/u', $body['content'], $keyMatches, PREG_OFFSET_CAPTURE);
            foreach ($keyMatches['key'] ?? [] as $keyMatch) {
                [$key, $keyOffset] = $keyMatch;
                $absoluteOffset = $body['start'] + $keyOffset;
                $result[$key][] = $this->lineNumberForOffset($content, $absoluteOffset);
            }
        }

        return $result;
    }

    /**
     * @return array<string, array<int>>
     */
    private function extractStaticObjectValueKeys(string $content): array
    {
        $result = [];
        preg_match_all('/const\s+[A-Z][A-Z0-9_]*_(?:LABEL_KEYS|KEY_MAP|TRANSLATION_KEYS)\b[^=]*=\s*\{/u', $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] ?? [] as $match) {
            [$declaration, $offset] = $match;
            $openBrace = strpos($declaration, '{');
            if ($openBrace === false) {
                continue;
            }

            $bodyStart = $offset + $openBrace;
            $body = $this->extractBracketBlock($content, $bodyStart, '{', '}');
            if ($body === null) {
                continue;
            }

            preg_match_all('/:\s*([\'"])(?<key>' . self::KEY_PATTERN . ')\1/u', $body['content'], $valueMatches, PREG_OFFSET_CAPTURE);
            foreach ($valueMatches['key'] ?? [] as $valueMatch) {
                [$key, $keyOffset] = $valueMatch;
                $absoluteOffset = $body['start'] + $keyOffset;
                $result[$key][] = $this->lineNumberForOffset($content, $absoluteOffset);
            }
        }

        return $result;
    }

    /**
     * @return array<string, array<int>>
     */
    private function extractPhpStaticArrayValueKeys(string $content): array
    {
        $result = [];
        preg_match_all('/(?:private|public|protected)?\s*const\s+[A-Z][A-Z0-9_]*_(?:TRANSLATION_KEYS|KEYS|LABEL_KEYS)\b[^=]*=\s*\[/u', $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] ?? [] as $match) {
            [$declaration, $offset] = $match;
            $openBracket = strpos($declaration, '[');
            if ($openBracket === false) {
                continue;
            }

            $bodyStart = $offset + $openBracket;
            $body = $this->extractBracketBlock($content, $bodyStart, '[', ']');
            if ($body === null) {
                continue;
            }

            preg_match_all('/=>\s*[\'"](?<key>' . self::KEY_PATTERN . ')[\'"]/u', $body['content'], $keyMatches, PREG_OFFSET_CAPTURE);
            foreach ($keyMatches['key'] ?? [] as $valueMatch) {
                [$key, $keyOffset] = $valueMatch;
                if ($key === '') {
                    continue;
                }

                $absoluteOffset = $body['start'] + $keyOffset;
                $result[$key][] = $this->lineNumberForOffset($content, $absoluteOffset);
            }
        }

        return $result;
    }

    /**
     * @return array<string, array<int>>
     */
    private function extractFrontendStructuredKeyUsages(string $content): array
    {
        preg_match_all('/\bkey\s*:\s*[\'"](?<key>' . self::KEY_PATTERN . ')[\'"]/u', $content, $matches, PREG_OFFSET_CAPTURE);

        $result = [];
        foreach ($matches['key'] ?? [] as $match) {
            [$key, $offset] = $match;
            $result[$key][] = $this->lineNumberForOffset($content, $offset);
        }

        return $result;
    }

    /**
     * @return array<string>
     */
    private function extractDynamicFrontendViolations(string $content, string $path): array
    {
        $violations = [];
        preg_match_all('/\b(?:t|tt|tc)\(\s*`[^`]*\$\{[^`]*`\s*\)/u', $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] ?? [] as $match) {
            [$expression, $offset] = $match;
            $line = $this->lineNumberForOffset($content, $offset);
            $violations[] = sprintf('%s:%d  %s', $path, $line, trim($expression));
        }

        return $violations;
    }

    /**
     * @param array<string, array{file: string, domain: string}> $declaredKeys
     * @param array<string, array<string>> $usedByKey
     * @return array<string>
     */
    private function buildMissingKeysReport(array $declaredKeys, array $usedByKey): array
    {
        $report = [];

        foreach ($usedByKey as $key => $references) {
            if (isset($declaredKeys[$key])) {
                continue;
            }

            foreach ($references as $reference) {
                $report[] = sprintf('%s  missing key `%s`', $reference, $key);
            }
        }

        sort($report);

        return $report;
    }

    /**
     * @param array<string, array{file: string, domain: string}> $declaredKeys
     * @param array<string> $usedKeys
     * @return array<string>
     */
    private function buildUnusedKeysReport(array $declaredKeys, array $usedKeys): array
    {
        $usedKeySet = array_fill_keys($usedKeys, true);
        $report = [];

        foreach ($declaredKeys as $key => $metadata) {
            if (isset($usedKeySet[$key])) {
                continue;
            }

            $report[] = sprintf('%s [%s]  unused key `%s`', $metadata['file'], $metadata['domain'], $key);
        }

        sort($report);

        return $report;
    }

    /**
     * @return array{content: string, start: int}|null
     */
    private function extractBracketBlock(string $content, int $openOffset, string $openChar, string $closeChar): ?array
    {
        $length = strlen($content);
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $escaped = false;

        for ($i = $openOffset; $i < $length; $i++) {
            $char = $content[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if (!$inDouble && !$inBacktick && $char === '\'') {
                $inSingle = !$inSingle;
                continue;
            }

            if (!$inSingle && !$inBacktick && $char === '"') {
                $inDouble = !$inDouble;
                continue;
            }

            if (!$inSingle && !$inDouble && $char === '`') {
                $inBacktick = !$inBacktick;
                continue;
            }

            if ($inSingle || $inDouble || $inBacktick) {
                continue;
            }

            if ($char === $openChar) {
                $depth++;
                continue;
            }

            if ($char === $closeChar) {
                $depth--;
                if ($depth === 0) {
                    return [
                        'content' => substr($content, $openOffset + 1, $i - $openOffset - 1),
                        'start' => $openOffset + 1,
                    ];
                }
            }
        }

        return null;
    }

    private function lineNumberForOffset(string $content, int $offset): int
    {
        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }
}
