<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Client\Test;

use Sowapps\SoManAgent\Script\Client\ConsoleClient;
use Sowapps\SoManAgent\Script\Application;
use Sowapps\SoManAgent\Script\Client\GitClient;
use Sowapps\SoManAgent\Script\RetryPolicy;

/**
 * Unit tests for {@see GitClient}.
 */
final class GitClientTest
{
    private string $originalCwd;

    /**
     * Saves the current working directory so tests can restore it after chdir calls.
     */
    public function __construct()
    {
        $this->originalCwd = (string) getcwd();
    }

    /**
     * Restores the working directory to prevent test isolation leaks.
     */
    public function __destruct()
    {
        chdir($this->originalCwd);
    }

    /**
     * Runs all GitClient unit tests and returns the number of failures.
     *
     * @return int Number of test failures (0 means all passed)
     */
    public function run(): int
    {
        $failed = 0;
        $failed += $this->testNetworkDisabledSkipsNetworkButRunsLocalCommands();
        $failed += $this->testOfflineEnvironmentTruthyValue();
        $failed += $this->testOfflineEnvironmentFalsyValue();

        return $failed;
    }

    private function testNetworkDisabledSkipsNetworkButRunsLocalCommands(): int
    {
        $root = $this->originalCwd . '/local/tmp/git-client-test-' . uniqid('', true);
        $repo = $root . '/repo';
        mkdir($repo, 0777, true);

        $logs = [];
        $console = new ConsoleClient(
            $repo,
            false,
            Application::getInstance(),
            static function (string $message) use (&$logs): void {
                $logs[] = $message;
            },
        );
        $git = new GitClient(false, $console, new RetryPolicy(0, 0), true);

        $previousCwd = getcwd();
        chdir($repo);
        try {
            $this->runShell('git init');

            $git->run('git config test.offline local-command-ran');
            $localValue = trim($git->captureReadonly('git config --get test.offline'));

            $git->runNetwork('git push origin main');
            $networkOutput = $git->captureNetwork('git ls-remote origin main');
            $git->pushUpstream('main');
            $git->pushForceWithLease('main', 'origin', '0000000000000000000000000000000000000000');
            $git->fetch();
            $git->deleteRemoteBranch('origin', 'main');
            $git->pullFastForwardOnly();
            $remoteVisible = $git->isRemoteBranchVisible('main');
        } finally {
            chdir($previousCwd !== false ? $previousCwd : $this->originalCwd);
            $this->removeDirectory($root);
        }

        if ($localValue !== 'local-command-ran') {
            echo "FAIL testNetworkDisabledSkipsNetworkButRunsLocalCommands: local git command did not run\n";
            return 1;
        }

        if ($networkOutput !== '') {
            echo "FAIL testNetworkDisabledSkipsNetworkButRunsLocalCommands: expected empty network output\n";
            return 1;
        }

        if ($remoteVisible) {
            echo "FAIL testNetworkDisabledSkipsNetworkButRunsLocalCommands: expected remote branch to be invisible\n";
            return 1;
        }

        foreach ($logs as $log) {
            if (str_contains($log, '[git-offline] Skip git network command:')) {
                echo "OK testNetworkDisabledSkipsNetworkButRunsLocalCommands\n";
                return 0;
            }
        }

        echo "FAIL testNetworkDisabledSkipsNetworkButRunsLocalCommands: expected offline network log\n";
        return 1;
    }

    private function testOfflineEnvironmentTruthyValue(): int
    {
        $previous = getenv('SOMANAGER_GIT_OFFLINE');

        try {
            putenv('SOMANAGER_GIT_OFFLINE=1');
            if (!GitClient::shouldDisableNetworkFromEnvironment()) {
                echo "FAIL testOfflineEnvironmentTruthyValue: expected 1 to disable network\n";
                return 1;
            }
        } finally {
            $this->restoreOfflineEnvironment($previous);
        }

        echo "OK testOfflineEnvironmentTruthyValue\n";
        return 0;
    }

    private function testOfflineEnvironmentFalsyValue(): int
    {
        $previous = getenv('SOMANAGER_GIT_OFFLINE');

        try {
            putenv('SOMANAGER_GIT_OFFLINE=false');
            if (GitClient::shouldDisableNetworkFromEnvironment()) {
                echo "FAIL testOfflineEnvironmentFalsyValue: expected false to allow network\n";
                return 1;
            }
        } finally {
            $this->restoreOfflineEnvironment($previous);
        }

        echo "OK testOfflineEnvironmentFalsyValue\n";
        return 0;
    }

    private function restoreOfflineEnvironment(string|false $previous): void
    {
        if ($previous === false) {
            putenv('SOMANAGER_GIT_OFFLINE');

            return;
        }

        putenv('SOMANAGER_GIT_OFFLINE=' . $previous);
    }

    private function runShell(string $command): void
    {
        exec($command . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                "Command failed with exit code %d: %s\n%s",
                $code,
                $command,
                implode("\n", $output),
            ));
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
