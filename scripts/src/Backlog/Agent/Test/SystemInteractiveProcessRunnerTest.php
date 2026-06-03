<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

use Sowapps\SoManAgent\Script\Backlog\Agent\Client\SystemInteractiveProcessRunner;

/**
 * Unit tests for SystemInteractiveProcessRunner.
 *
 * Uses real but very short-lived child processes (/bin/sh -c true / sleep) so we exercise the actual
 * proc_open path without relying on AI client binaries.
 */
final class SystemInteractiveProcessRunnerTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testSpawnsAndReturnsZeroExit();
        $failed += $this->testReturnsNonZeroExitCode();
        $failed += $this->testOnSpawnedReceivesClientPid();

        return $failed;
    }

    private function testSpawnsAndReturnsZeroExit(): int
    {
        $runner = new SystemInteractiveProcessRunner();
        $result = $runner->run('/bin/sh', ['-c', 'exit 0'], sys_get_temp_dir(), $this->minimalEnv());

        if ($result->exitCode !== 0) {
            echo "FAIL testSpawnsAndReturnsZeroExit: expected exit 0, got {$result->exitCode}\n";
            return 1;
        }
        if ($result->clientPid === null || $result->clientPid <= 0) {
            echo "FAIL testSpawnsAndReturnsZeroExit: expected positive clientPid, got "
                . var_export($result->clientPid, true) . "\n";
            return 1;
        }
        echo "OK testSpawnsAndReturnsZeroExit\n";
        return 0;
    }

    private function testReturnsNonZeroExitCode(): int
    {
        $runner = new SystemInteractiveProcessRunner();
        $result = $runner->run('/bin/sh', ['-c', 'exit 42'], sys_get_temp_dir(), $this->minimalEnv());

        if ($result->exitCode !== 42) {
            echo "FAIL testReturnsNonZeroExitCode: expected 42, got {$result->exitCode}\n";
            return 1;
        }
        echo "OK testReturnsNonZeroExitCode\n";
        return 0;
    }

    private function testOnSpawnedReceivesClientPid(): int
    {
        $runner = new SystemInteractiveProcessRunner();
        $capturedPid = null;

        $result = $runner->run(
            '/bin/sh',
            ['-c', 'exit 0'],
            sys_get_temp_dir(),
            $this->minimalEnv(),
            function (int $pid) use (&$capturedPid): void {
                $capturedPid = $pid;
            },
        );

        if ($capturedPid === null || $capturedPid <= 0) {
            echo "FAIL testOnSpawnedReceivesClientPid: expected positive pid in callback, got "
                . var_export($capturedPid, true) . "\n";
            return 1;
        }
        if ($result->clientPid !== $capturedPid) {
            echo "FAIL testOnSpawnedReceivesClientPid: result.clientPid mismatch with callback pid\n";
            return 1;
        }
        echo "OK testOnSpawnedReceivesClientPid\n";
        return 0;
    }

    /**
     * @return array<string, string>
     */
    private function minimalEnv(): array
    {
        return ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
    }
}
