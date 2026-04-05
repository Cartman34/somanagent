<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Searches a term across backend PHP and frontend TS/TSX source files.
 */
final class GrepUsageRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Search a term across backend PHP and frontend TS/TSX source files';
    }

    protected function getArguments(): array
    {
        return [
            ['name' => '<term>', 'description' => 'Search term to find in source files'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--backend', 'description' => 'Search only in backend/src/'],
            ['name' => '--frontend', 'description' => 'Search only in frontend/src/'],
            ['name' => '--context', 'description' => 'Show N lines of context before/after each match (default: 0)'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/claude/grep-usage.php UserRepository',
            'php scripts/claude/grep-usage.php --backend AgentController',
            'php scripts/claude/grep-usage.php --frontend useAgent --context 2',
        ];
    }

    public function run(array $args): int
    {
        if ($args === []) {
            $this->console->fail('Missing search term. Usage: php scripts/claude/grep-usage.php <term> [--backend|--frontend] [--context N]');
        }

        $term    = null;
        $scope   = 'all';
        $context = 0;

        for ($i = 0; $i < count($args); $i++) {
            if ($args[$i] === '--backend')  { $scope = 'backend'; continue; }
            if ($args[$i] === '--frontend') { $scope = 'frontend'; continue; }
            if ($args[$i] === '--context' && isset($args[$i + 1])) {
                $context = (int) $args[++$i];
                continue;
            }
            if ($term === null) {
                $term = $args[$i];
            }
        }

        if ($term === null) {
            $this->console->fail('Missing search term.');
        }

        $dirs = [];
        if ($scope !== 'frontend') $dirs[] = ['path' => $this->projectRoot . '/backend/src', 'exts' => ['php']];
        if ($scope !== 'backend')  $dirs[] = ['path' => $this->projectRoot . '/frontend/src', 'exts' => ['ts', 'tsx']];

        $results = [];

        foreach ($dirs as $dirConf) {
            if (!is_dir($dirConf['path'])) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dirConf['path'], \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                if (!in_array($file->getExtension(), $dirConf['exts'])) continue;

                $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
                foreach ($lines as $i => $line) {
                    if (stripos($line, $term) !== false) {
                        $results[] = [
                            'file'     => str_replace($this->projectRoot . '/', '', $file->getPathname()),
                            'lineNum'  => $i + 1,
                            'content'  => $line,
                            'allLines' => $context > 0 ? $lines : [],
                        ];
                    }
                }
            }
        }

        if (empty($results)) {
            echo "No results for \"{$term}\".\n";
            return 0;
        }

        echo "\nSearch: \"{$term}\" — " . count($results) . " match(es)\n\n";

        $prevFile = null;
        foreach ($results as $r) {
            if ($r['file'] !== $prevFile) {
                echo "── {$r['file']} ──\n";
                $prevFile = $r['file'];
            }

            if ($context > 0 && !empty($r['allLines'])) {
                $start = max(0, $r['lineNum'] - 1 - $context);
                $end   = min(count($r['allLines']) - 1, $r['lineNum'] - 1 + $context);
                for ($i = $start; $i <= $end; $i++) {
                    $marker = ($i === $r['lineNum'] - 1) ? '>' : ' ';
                    $lineNo = str_pad((string)($i + 1), 4);
                    echo "  {$marker} {$lineNo}: {$r['allLines'][$i]}\n";
                }
                echo "\n";
            } else {
                $lineNo = str_pad((string)$r['lineNum'], 4);
                echo "  {$lineNo}: " . trim($r['content']) . "\n";
            }
        }

        echo "\n";

        return 0;
    }
}
