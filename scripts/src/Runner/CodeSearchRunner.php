<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Searches a term across backend PHP, frontend TS/TSX, scripts PHP, doc Markdown, and YAML resource files.
 */
final class CodeSearchRunner extends AbstractScriptRunner
{
    public const NAME = 'code-search';

    protected function getName(): string
    {
        return self::NAME;
    }

    private const ENGINE_RG = 'rg';
    private const ENGINE_PHP = 'php';
    private const DEFAULT_ENGINE = self::ENGINE_RG;

    private const SCOPE_ALL = 'all';
    private const SCOPE_BACKEND = 'backend';
    private const SCOPE_FRONTEND = 'frontend';
    private const SCOPE_SCRIPTS = 'scripts';
    private const SCOPE_DOC = 'doc';
    private const SCOPE_RESOURCES = 'resources';

    /** @var array<string, array<int, array{path: string, exts: array<int, string>, globs: array<int, string>, recursive?: bool}>> */
    private const SCOPE_DIRECTORIES = [
        self::SCOPE_ALL => [
            ['path' => 'backend/src', 'exts' => ['php'], 'globs' => ['*.php']],
            ['path' => 'frontend/src', 'exts' => ['ts', 'tsx'], 'globs' => ['*.ts', '*.tsx']],
            ['path' => 'scripts/src', 'exts' => ['php'], 'globs' => ['*.php']],
            ['path' => 'doc', 'exts' => ['md'], 'globs' => ['*.md']],
            ['path' => 'scripts/resources', 'exts' => ['yaml'], 'globs' => ['*.yaml']],
            ['path' => '.', 'exts' => ['md'], 'globs' => ['*.md'], 'recursive' => false],
        ],
        self::SCOPE_BACKEND => [
            ['path' => 'backend/src', 'exts' => ['php'], 'globs' => ['*.php']],
        ],
        self::SCOPE_FRONTEND => [
            ['path' => 'frontend/src', 'exts' => ['ts', 'tsx'], 'globs' => ['*.ts', '*.tsx']],
        ],
        self::SCOPE_SCRIPTS => [
            ['path' => 'scripts/src', 'exts' => ['php'], 'globs' => ['*.php']],
        ],
        self::SCOPE_DOC => [
            ['path' => 'doc', 'exts' => ['md'], 'globs' => ['*.md']],
            ['path' => '.', 'exts' => ['md'], 'globs' => ['*.md'], 'recursive' => false],
        ],
        self::SCOPE_RESOURCES => [
            ['path' => 'scripts/resources', 'exts' => ['yaml'], 'globs' => ['*.yaml']],
        ],
    ];

    protected function getDescription(): string
    {
        return 'Search a term across backend PHP, frontend TS/TSX, scripts PHP, doc Markdown, and YAML resource files';
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
            ['name' => '--engine', 'description' => 'Search engine to use: rg (default) or php'],
            ['name' => '--backend', 'description' => 'Search only in backend/src/'],
            ['name' => '--frontend', 'description' => 'Search only in frontend/src/'],
            ['name' => '--scripts', 'description' => 'Search only in scripts/src/'],
            ['name' => '--doc', 'description' => 'Search only in doc/**/*.md and root *.md files (AGENTS.md, CLAUDE.md, …)'],
            ['name' => '--resources', 'description' => 'Search only in scripts/resources/**/*.yaml'],
            ['name' => '--context', 'description' => 'Show N lines of context before/after each match (default: 0)'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/code-search.php UserRepository',
            'php scripts/code-search.php UserRepository --engine rg',
            'php scripts/code-search.php UserRepository --engine php',
            'php scripts/code-search.php --backend AgentController',
            'php scripts/code-search.php --frontend useAgent --context 2',
            'php scripts/code-search.php --scripts CodeSearchRunner',
            'php scripts/code-search.php --doc SOMANAGER_AGENT',
            'php scripts/code-search.php --resources review-request',
        ];
    }

    /**
     * Executes a source search across configured scopes (backend, frontend, scripts, doc, resources).
     *
     * @param list<string> $args
     * @return int
     */
    public function run(array $args): int
    {
        if ($args === []) {
            $this->console->fail('Missing search term. Usage: php scripts/code-search.php <term> [--backend|--frontend|--scripts|--doc|--resources] [--context N]');
        }

        $term    = null;
        $scope   = self::SCOPE_ALL;
        $context = 0;
        $engine  = self::DEFAULT_ENGINE;

        for ($i = 0; $i < count($args); $i++) {
            if ($args[$i] === '--backend') {
                $scope = self::SCOPE_BACKEND;
                continue;
            }
            if ($args[$i] === '--frontend') {
                $scope = self::SCOPE_FRONTEND;
                continue;
            }
            if ($args[$i] === '--scripts') {
                $scope = self::SCOPE_SCRIPTS;
                continue;
            }
            if ($args[$i] === '--doc') {
                $scope = self::SCOPE_DOC;
                continue;
            }
            if ($args[$i] === '--resources') {
                $scope = self::SCOPE_RESOURCES;
                continue;
            }
            if ($args[$i] === '--engine' && isset($args[$i + 1])) {
                $engine = strtolower((string) $args[++$i]);
                continue;
            }
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

        if (!in_array($engine, [self::ENGINE_RG, self::ENGINE_PHP], true)) {
            $this->console->fail(sprintf(
                'Invalid --engine value. Allowed values: %s, %s.',
                self::ENGINE_RG,
                self::ENGINE_PHP,
            ));
        }

        if ($engine === self::ENGINE_RG && $this->commandSucceeds('command -v rg')) {
            return $this->runRipgrepSearch($term, $scope, $context);
        }

        if ($engine === self::ENGINE_RG) {
            $this->console->warn('rg not found, falling back to the PHP scanner.');
        }

        return $this->runPhpSearch($term, $scope, $context);
    }

    private function runPhpSearch(string $term, string $scope, int $context): int
    {
        $results = [];

        foreach ($this->getScopeDirectories($scope) as $dirConfig) {
            $path = $this->projectRoot . '/' . $dirConfig['path'];
            if (!is_dir($path)) {
                continue;
            }

            $recursive = $dirConfig['recursive'] ?? true;

            if ($recursive) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
                );
            } else {
                $iterator = new \DirectoryIterator($path);
            }

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                if (!in_array($file->getExtension(), $dirConfig['exts'], true)) {
                    continue;
                }

                $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
                if ($lines === false) {
                    throw new \RuntimeException(sprintf('Unable to read file: %s', $file->getPathname()));
                }
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

    private function runRipgrepSearch(string $term, string $scope, int $context): int
    {
        $commands = [];

        foreach ($this->getScopeDirectories($scope) as $dirConfig) {
            $commands[] = $this->buildRipgrepCommand($term, $context, $dirConfig['globs'], [$dirConfig['path']], $dirConfig['recursive'] ?? true);
        }

        $outputs = [];
        foreach ($commands as $command) {
            [$code, $output] = $this->captureWithExitCode($command);
            if ($code > 1) {
                throw new \RuntimeException(sprintf("Command failed with exit code %d: %s\n%s", $code, $command, $output));
            }
            if (trim($output) !== '') {
                $outputs[] = trim($output);
            }
        }

        if ($outputs === []) {
            echo "No results for \"{$term}\".\n";

            return 0;
        }

        echo implode("\n", $outputs) . "\n";

        return 0;
    }

    /**
     * @return array<int, array{path: string, exts: array<int, string>, globs: array<int, string>, recursive?: bool}>
     */
    private function getScopeDirectories(string $scope): array
    {
        return self::SCOPE_DIRECTORIES[$scope] ?? throw new \RuntimeException("Unknown scope: {$scope}");
    }

    /**
     * @param array<string> $globs
     * @param array<string> $paths
     */
    private function buildRipgrepCommand(string $term, int $context, array $globs, array $paths, bool $recursive = true): string
    {
        $parts = ['rg', '-n', '-i', '--color', 'never'];

        if (!$recursive) {
            $parts[] = '--max-depth';
            $parts[] = '0';
        }

        if ($context > 0) {
            $parts[] = '-C';
            $parts[] = (string) $context;
        }

        foreach ($globs as $glob) {
            $parts[] = '-g';
            $parts[] = $glob;
        }

        $parts[] = $term;

        foreach ($paths as $path) {
            $parts[] = $path;
        }

        return implode(' ', array_map('escapeshellarg', $parts));
    }

    private function commandSucceeds(string $command): bool
    {
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);

        return $code === 0;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function captureWithExitCode(string $command): array
    {
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    }
}
