<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv\Test;

use SoManAgent\Script\Console;
use SoManAgent\Script\DevEnv\Installer\ProjectDepsInstaller;

/**
 * Unit tests for ProjectDepsInstaller.
 *
 * Uses a temporary directory as the fake backend root so that .env file
 * parsing can be exercised without touching the real project files.
 */
final class ProjectDepsInstallerTest
{
    private string $tmpDir;

    /**
     * Creates a fresh temporary directory used as a fake backend root for all test cases.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/somanagent_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0o700, true);
    }

    /**
     * Removes the temporary directory after all tests have run.
     */
    public function __destruct()
    {
        $this->removeTmpDir($this->tmpDir);
    }

    /**
     * Runs all test cases and returns the total number of failures.
     * @api
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testDbDownThrowsWithClearMessage();
        $failed += $this->testMinimalModeRunsMigrationsWhenPhpNodeDown();
        $failed += $this->testFullModeRunsMigrationsComposerNpm();
        $failed += $this->testEnvLocalOverridesEnvForDatabaseUrl();
        $failed += $this->testDbHostNormalisedFromDockerServiceNameToLocalhost();

        return $failed;
    }

    /**
     * When the db container is not running, runProjectSteps() must throw with a
     * message directing the user to start the server.
     */
    private function testDbDownThrowsWithClearMessage(): int
    {
        $dockerRunner = new FakeCommandRunner();
        $this->setContainerState($dockerRunner, 'somanagent_db', false);

        $this->writeEnv($this->tmpDir, 'DATABASE_URL="postgresql://u:p@db:5432/app?serverVersion=16"');

        $shell = new FakeShellRunner();
        $installer = $this->makeInstaller($shell, $dockerRunner);

        try {
            $installer->runProjectSteps();
            echo "FAIL testDbDownThrowsWithClearMessage: expected RuntimeException, none thrown\n";
            return 1;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'db container is not running')) {
                echo "FAIL testDbDownThrowsWithClearMessage: unexpected message: {$e->getMessage()}\n";
                return 1;
            }
            if (!str_contains($e->getMessage(), 'server.php start')) {
                echo "FAIL testDbDownThrowsWithClearMessage: message must mention server.php start: {$e->getMessage()}\n";
                return 1;
            }
        }

        echo "OK testDbDownThrowsWithClearMessage\n";
        return 0;
    }

    /**
     * Minimal mode: db up, php and node containers down.
     * Migrations must run; composer and npm must be skipped (no exception).
     */
    private function testMinimalModeRunsMigrationsWhenPhpNodeDown(): int
    {
        $dockerRunner = new FakeCommandRunner();
        $this->setContainerState($dockerRunner, 'somanagent_db', true);
        $this->setContainerState($dockerRunner, 'somanagent_php', false);
        $this->setContainerState($dockerRunner, 'somanagent_node', false);

        $this->writeEnv($this->tmpDir, 'DATABASE_URL="postgresql://u:p@db:5432/app?serverVersion=16"');

        $shell = new FakeShellRunner();
        $installer = $this->makeInstaller($shell, $dockerRunner);

        try {
            $installer->runProjectSteps();
        } catch (\RuntimeException $e) {
            echo "FAIL testMinimalModeRunsMigrationsWhenPhpNodeDown: unexpected exception: {$e->getMessage()}\n";
            return 1;
        }

        $calls = $shell->getCalls();
        $migrationRan = false;
        foreach ($calls as $call) {
            if (str_contains($call, 'doctrine:migrations:migrate')) {
                $migrationRan = true;
                break;
            }
        }
        if (!$migrationRan) {
            echo "FAIL testMinimalModeRunsMigrationsWhenPhpNodeDown: no migration command run\n";
            return 1;
        }

        $composerRan = false;
        foreach ($calls as $call) {
            if (str_contains($call, 'composer install')) {
                $composerRan = true;
                break;
            }
        }
        if ($composerRan) {
            echo "FAIL testMinimalModeRunsMigrationsWhenPhpNodeDown: composer should be skipped, but was run\n";
            return 1;
        }

        echo "OK testMinimalModeRunsMigrationsWhenPhpNodeDown\n";
        return 0;
    }

    /**
     * Full mode: db, php, and node all up.
     * Migrations, composer, and npm must all run.
     */
    private function testFullModeRunsMigrationsComposerNpm(): int
    {
        $dockerRunner = new FakeCommandRunner();
        $this->setContainerState($dockerRunner, 'somanagent_db', true);
        $this->setContainerState($dockerRunner, 'somanagent_php', true);
        $this->setContainerState($dockerRunner, 'somanagent_node', true);

        $this->writeEnv($this->tmpDir, 'DATABASE_URL="postgresql://u:p@db:5432/app?serverVersion=16"');

        $shell = new FakeShellRunner();
        $installer = $this->makeInstaller($shell, $dockerRunner);

        try {
            $installer->runProjectSteps();
        } catch (\RuntimeException $e) {
            echo "FAIL testFullModeRunsMigrationsComposerNpm: unexpected exception: {$e->getMessage()}\n";
            return 1;
        }

        $calls = $shell->getCalls();

        foreach (['doctrine:migrations:migrate', 'composer install', 'npm install'] as $keyword) {
            $found = false;
            foreach ($calls as $call) {
                if (str_contains($call, $keyword)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                echo "FAIL testFullModeRunsMigrationsComposerNpm: '{$keyword}' not found in commands\n";
                return 1;
            }
        }

        echo "OK testFullModeRunsMigrationsComposerNpm\n";
        return 0;
    }

    /**
     * .env.local overrides .env for DATABASE_URL.
     * The value from .env.local must be used for the migration command.
     */
    private function testEnvLocalOverridesEnvForDatabaseUrl(): int
    {
        $dockerRunner = new FakeCommandRunner();
        $this->setContainerState($dockerRunner, 'somanagent_db', true);
        $this->setContainerState($dockerRunner, 'somanagent_php', false);
        $this->setContainerState($dockerRunner, 'somanagent_node', false);

        $this->writeEnv($this->tmpDir, 'DATABASE_URL="postgresql://u:p@db:5432/base_env"');
        $this->writeEnvLocal($this->tmpDir, 'DATABASE_URL="postgresql://u:p@localhost:5432/base_local"');

        $shell = new FakeShellRunner();
        $installer = $this->makeInstaller($shell, $dockerRunner);
        $installer->runProjectSteps();

        $found = false;
        foreach ($shell->getCalls() as $call) {
            if (str_contains($call, 'base_local')) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            echo "FAIL testEnvLocalOverridesEnvForDatabaseUrl: .env.local value not used\n";
            return 1;
        }

        $wrongDb = false;
        foreach ($shell->getCalls() as $call) {
            if (str_contains($call, 'base_env')) {
                $wrongDb = true;
                break;
            }
        }
        if ($wrongDb) {
            echo "FAIL testEnvLocalOverridesEnvForDatabaseUrl: .env value leaked into command\n";
            return 1;
        }

        echo "OK testEnvLocalOverridesEnvForDatabaseUrl\n";
        return 0;
    }

    /**
     * Docker service hostname `db` must be replaced with `localhost` in the
     * DATABASE_URL passed to bin/console so the host PHP CLI can connect.
     */
    private function testDbHostNormalisedFromDockerServiceNameToLocalhost(): int
    {
        $dockerRunner = new FakeCommandRunner();
        $this->setContainerState($dockerRunner, 'somanagent_db', true);
        $this->setContainerState($dockerRunner, 'somanagent_php', false);
        $this->setContainerState($dockerRunner, 'somanagent_node', false);

        $this->writeEnv($this->tmpDir, 'DATABASE_URL="postgresql://u:p@db:5432/mydb?serverVersion=16"');

        $shell = new FakeShellRunner();
        $installer = $this->makeInstaller($shell, $dockerRunner);
        $installer->runProjectSteps();

        $normalized = false;
        foreach ($shell->getCalls() as $call) {
            if (str_contains($call, '@localhost:5432/')) {
                $normalized = true;
                break;
            }
        }
        if (!$normalized) {
            echo "FAIL testDbHostNormalisedFromDockerServiceNameToLocalhost: @localhost:5432 not found in commands\n";
            return 1;
        }

        $stillDocker = false;
        foreach ($shell->getCalls() as $call) {
            if (str_contains($call, '@db:5432/')) {
                $stillDocker = true;
                break;
            }
        }
        if ($stillDocker) {
            echo "FAIL testDbHostNormalisedFromDockerServiceNameToLocalhost: @db:5432 leaked into command\n";
            return 1;
        }

        echo "OK testDbHostNormalisedFromDockerServiceNameToLocalhost\n";
        return 0;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeInstaller(FakeShellRunner $shell, FakeCommandRunner $dockerRunner): ProjectDepsInstaller
    {
        return new ProjectDepsInstaller($shell, Console::getInstance(), $this->tmpDir, $dockerRunner);
    }

    /**
     * Registers a docker inspect response for the given container name.
     */
    private function setContainerState(FakeCommandRunner $runner, string $name, bool $running): void
    {
        $runner->setOutput(
            'docker inspect --format={{.State.Running}} ' . escapeshellarg($name) . ' 2>/dev/null',
            $running ? 'true' : 'false',
        );
    }

    private function writeEnv(string $dir, string $content): void
    {
        file_put_contents($dir . '/.env', $content . "\n");
    }

    private function writeEnvLocal(string $dir, string $content): void
    {
        file_put_contents($dir . '/.env.local', $content . "\n");
    }

    private function removeTmpDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTmpDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
