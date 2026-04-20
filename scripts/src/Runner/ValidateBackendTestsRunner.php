<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Runs targeted PHPUnit checks for modified backend services when a dedicated test exists.
 */
final class ValidateBackendTestsRunner extends AbstractScriptRunner
{
    private const ALL_OPTION = '--all';
    private const LOCAL_TEST_ENV = 'SOMANAGENT_PHPUNIT_LOCAL=1';
    private const LOCAL_TEST_SUITE = 'local-unit';

    /**
     * @var array<string, string>
     */
    private const FORBIDDEN_PATTERNS = [
        'Local unit tests must extend App\\Tests\\Support\\LocalUnitTestCase.' => '/extends\s+LocalUnitTestCase\b/',
        'Symfony kernel boot is forbidden in local unit tests.' => '/\b(KernelTestCase|WebTestCase|bootKernel\s*\(|createClient\s*\()\b/',
        'Database access is forbidden in local unit tests.' => '/Doctrine\\\\ORM\\\\EntityManagerInterface|Doctrine\\\\Persistence\\\\ManagerRegistry|Doctrine\\\\DBAL\\\\Connection|\bnew\s+PDO\s*\(/',
        'Redis access is forbidden in local unit tests.' => '/Predis\\\\|use\s+Redis\b|\bnew\s+Redis\s*\(/',
        'Real HTTP clients are forbidden in local unit tests.' => '/GuzzleHttp\\\\Client|Symfony\\\\Component\\\\HttpClient\\\\|HttpClient::create\s*\(|curl_(init|exec|multi_exec)\s*\(/',
        'Direct remote URL reads are forbidden in local unit tests.' => '/file_get_contents\s*\(\s*[\'"]https?:\/\//',
        'External AI/VCS adapters are forbidden in local unit tests.' => '/App\\\\Adapter\\\\AI\\\\|App\\\\Adapter\\\\VCS\\\\(GitHubAdapter|GitLabAdapter|ClaudeApiConnector|ClaudeCliConnector|CodexApiConnector|CodexCliConnector|OpenCodeCliConnector)\b/',
    ];

    protected function getDescription(): string
    {
        return 'Run isolated local PHPUnit checks for backend unit tests from WSL without Docker services';
    }

    protected function getArguments(): array
    {
        return [
            ['name' => '<file> [file...]', 'description' => 'Review-scope files used to detect modified backend services'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--all', 'description' => 'Run the whole backend PHPUnit suite instead of mapped service tests'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/validate-backend-tests.php backend/src/Service/VcsRepositoryUrlService.php',
            'php scripts/validate-backend-tests.php backend/src/Service/AgentModelRecommendationPolicyResolver.php scripts/review.php',
            'php scripts/validate-backend-tests.php --all',
        ];
    }

    /**
     * Runs PHPUnit for modified backend services that have a dedicated mapped test file, or the full suite.
     *
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        if ($args === [self::ALL_OPTION]) {
            $testPaths = $this->collectLocalUnitTestFiles();
            $violations = $this->collectIsolationViolations($testPaths);
            if ($violations !== []) {
                $this->printIsolationViolations($violations);
                return 1;
            }

            return $this->runPhpUnit([], true);
        }

        if ($args === [] || in_array(self::ALL_OPTION, $args, true)) {
            fwrite(STDERR, "Usage: php scripts/validate-backend-tests.php <file> [file...]\n");
            fwrite(STDERR, "   or: php scripts/validate-backend-tests.php --all\n");
            return 1;
        }

        $serviceFiles = $this->collectServiceFiles($args);
        if ($serviceFiles === []) {
            echo "Backend service tests: SKIP (no modified backend service)\n";
            return 0;
        }

        $existingTests = [];
        $missingTests = [];

        foreach ($serviceFiles as $serviceFile) {
            $mappedTest = $this->mapServiceToTest($serviceFile);
            if ($mappedTest !== null && is_file($this->projectRoot . '/' . $mappedTest)) {
                $existingTests[$mappedTest] = true;
                continue;
            }

            if ($mappedTest !== null) {
                $missingTests[] = $serviceFile . ' -> ' . $mappedTest;
            }
        }

        if ($missingTests !== []) {
            echo "Backend service tests without dedicated test:\n";
            foreach ($missingTests as $line) {
                echo '  - ' . $line . "\n";
            }
        }

        if ($existingTests === []) {
            echo "Backend service tests: SKIP (no dedicated PHPUnit test found)\n";
            return 0;
        }

        $violations = $this->collectIsolationViolations(array_keys($existingTests));
        if ($violations !== []) {
            $this->printIsolationViolations($violations);
            return 1;
        }

        return $this->runPhpUnit(array_keys($existingTests));
    }

    /**
     * @param array<string> $files
     * @return array<string>
     */
    private function collectServiceFiles(array $files): array
    {
        $serviceFiles = [];

        foreach ($files as $file) {
            $normalized = ltrim(str_replace('\\', '/', $file), './');

            if (!str_starts_with($normalized, 'backend/src/Service/') || !str_ends_with($normalized, '.php')) {
                continue;
            }

            if (!is_file($this->projectRoot . '/' . $normalized)) {
                continue;
            }

            $serviceFiles[$normalized] = true;
        }

        return array_keys($serviceFiles);
    }

    private function mapServiceToTest(string $serviceFile): ?string
    {
        if (!preg_match('#^backend/src/Service/(.+)\.php$#', $serviceFile, $matches)) {
            return null;
        }

        return 'backend/tests/Unit/Service/' . $matches[1] . 'Test.php';
    }

    /**
     * @param array<string> $testPaths
     */
    private function runPhpUnit(array $testPaths = [], bool $runAll = false): int
    {
        $command = self::LOCAL_TEST_ENV . ' php backend/vendor/bin/phpunit --configuration backend/phpunit.dist.xml';

        if ($runAll) {
            $command .= ' --testsuite ' . escapeshellarg(self::LOCAL_TEST_SUITE);
        } elseif ($testPaths !== []) {
            $command .= ' ' . implode(' ', array_map('escapeshellarg', $testPaths));
        }

        $exitCode = $this->app->runCommand($command);

        return $exitCode === 0 ? 0 : 1;
    }

    /**
     * @return array<string>
     */
    private function collectLocalUnitTestFiles(): array
    {
        $root = $this->projectRoot . '/backend/tests/Unit';
        if (!is_dir($root)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $paths = [];

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($this->projectRoot) + 1);
            $paths[] = str_replace('\\', '/', $relativePath);
        }

        sort($paths);

        return $paths;
    }

    /**
     * @param array<string> $testPaths
     * @return array<string>
     */
    private function collectIsolationViolations(array $testPaths): array
    {
        $violations = [];

        foreach ($testPaths as $testPath) {
            $absolutePath = $this->projectRoot . '/' . $testPath;
            $content = file_get_contents($absolutePath);
            if ($content === false) {
                $violations[] = $testPath . ': unreadable test file';
                continue;
            }

            foreach (self::FORBIDDEN_PATTERNS as $message => $pattern) {
                if ($message === 'Local unit tests must extend App\\Tests\\Support\\LocalUnitTestCase.') {
                    if (preg_match($pattern, $content) === 1) {
                        continue;
                    }

                    $violations[] = $testPath . ': ' . $message;
                    continue;
                }

                $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
                foreach ($lines as $index => $line) {
                    if (preg_match($pattern, $line) !== 1) {
                        continue;
                    }

                    $violations[] = sprintf('%s:%d %s', $testPath, $index + 1, $message);
                }
            }
        }

        return $violations;
    }

    /**
     * @param array<string> $violations
     */
    private function printIsolationViolations(array $violations): void
    {
        echo "Local unit test isolation violations:\n";

        foreach ($violations as $violation) {
            echo '  - ' . $violation . "\n";
        }
    }
}
