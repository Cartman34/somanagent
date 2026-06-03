<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

use Sowapps\SoManAgent\Script\Backlog\Agent\Client\OpenCodeAgentLauncher;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use Sowapps\SoManAgent\Script\Backlog\BacklogPaths;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\ProcessRunner;

/**
 * Unit tests for OpenCodeAgentLauncher hooks.
 */
final class OpenCodeAgentLauncherTest
{
    private const MODEL_NAME = 'gpt-4';

    private const RESUME_SESSION_ID = 'session-abc';

    private string $tmpDir;

    /**
     * Creates a temporary test root used for worktree fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-opencode-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    /**
     * Removes the temporary test root.
     */
    public function __destruct()
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testClient();
        $failed += $this->testIsAvailableUsesProcessRunner();
        $failed += $this->testPrepareWorktreeCreatesFileWhenAbsent();
        $failed += $this->testPrepareWorktreeAddsInstructionToExistingFile();
        $failed += $this->testPrepareWorktreeIsIdempotent();
        $failed += $this->testPrepareWorktreeNormalizesNonArrayInstructions();
        $failed += $this->testPrepareWorktreePreservesOtherKeys();
        $failed += $this->testPrepareWorktreeAddsBacklogPermission();
        $failed += $this->testPrepareWorktreeIsBacklogPermissionIdempotent();
        $failed += $this->testBuildLaunchCommandInitial();
        $failed += $this->testBuildLaunchCommandInitialPrompt();
        $failed += $this->testBuildLaunchCommandContinue();
        $failed += $this->testBuildLaunchCommandResumeId();
        $failed += $this->testBuildLaunchCommandIncludesPermissionFlags();
        $failed += $this->testCaptureCurrentSessionIdParsesFirstRow();
        $failed += $this->testCaptureCurrentSessionIdReturnsNullWhenEmpty();
        $failed += $this->testListSessionsReturnsEmptyOnNullOutput();
        $failed += $this->testListSessionsParsesRows();

        return $failed;
    }

    private function testClient(): int
    {
        $launcher = new OpenCodeAgentLauncher($this->makeProcessRunner(true));

        if ($launcher->client() !== AgentClient::OPENCODE) {
            echo "FAIL testClient: expected OPENCODE\n";

            return 1;
        }

        echo "OK testClient\n";

        return 0;
    }

    private function testIsAvailableUsesProcessRunner(): int
    {
        $launcher = new OpenCodeAgentLauncher($this->makeProcessRunner(false));

        if ($launcher->isAvailable()) {
            echo "FAIL testIsAvailableUsesProcessRunner: expected unavailable\n";

            return 1;
        }

        echo "OK testIsAvailableUsesProcessRunner\n";

        return 0;
    }

    private function testPrepareWorktreeCreatesFileWhenAbsent(): int
    {
        $worktree = $this->makeWorktree('create');
        $launcher = new OpenCodeAgentLauncher($this->makeProcessRunner(true));
        $launcher->prepareWorktree($worktree, $worktree . '/local/agent-context.md');

        $configPath = $worktree . '/opencode.json';
        if (!is_file($configPath)) {
            echo "FAIL testPrepareWorktreeCreatesFileWhenAbsent: file not created\n";

            return 1;
        }
        $data = json_decode((string) file_get_contents($configPath), true);
        if (!is_array($data) || ($data['instructions'] ?? null) !== ['local/agent-context.md']) {
            echo "FAIL testPrepareWorktreeCreatesFileWhenAbsent: unexpected content\n";

            return 1;
        }

        echo "OK testPrepareWorktreeCreatesFileWhenAbsent\n";

        return 0;
    }

    private function testPrepareWorktreeAddsInstructionToExistingFile(): int
    {
        $worktree = $this->makeWorktree('add');
        file_put_contents(
            $worktree . '/opencode.json',
            json_encode(['instructions' => ['other-file.md']]) . "\n",
        );
        $launcher = new OpenCodeAgentLauncher($this->makeProcessRunner(true));
        $launcher->prepareWorktree($worktree, $worktree . '/local/agent-context.md');

        $data = json_decode((string) file_get_contents($worktree . '/opencode.json'), true);
        $instructions = $data['instructions'] ?? [];
        if (!in_array('local/agent-context.md', $instructions, true) || !in_array('other-file.md', $instructions, true)) {
            echo "FAIL testPrepareWorktreeAddsInstructionToExistingFile: missing expected instructions\n";

            return 1;
        }

        echo "OK testPrepareWorktreeAddsInstructionToExistingFile\n";

        return 0;
    }

    private function testPrepareWorktreeIsIdempotent(): int
    {
        $worktree = $this->makeWorktree('idempotent');
        file_put_contents(
            $worktree . '/opencode.json',
            json_encode(['instructions' => ['local/agent-context.md']]) . "\n",
        );
        $launcher = new OpenCodeAgentLauncher($this->makeProcessRunner(true));
        $launcher->prepareWorktree($worktree, $worktree . '/local/agent-context.md');
        $launcher->prepareWorktree($worktree, $worktree . '/local/agent-context.md');

        $data = json_decode((string) file_get_contents($worktree . '/opencode.json'), true);
        $instructions = $data['instructions'] ?? [];
        $count = count(array_filter($instructions, static fn($v): bool => $v === 'local/agent-context.md'));
        if ($count !== 1) {
            echo "FAIL testPrepareWorktreeIsIdempotent: instruction duplicated (count={$count})\n";

            return 1;
        }

        echo "OK testPrepareWorktreeIsIdempotent\n";

        return 0;
    }

    private function testPrepareWorktreeNormalizesNonArrayInstructions(): int
    {
        $worktree = $this->makeWorktree('normalize');
        file_put_contents(
            $worktree . '/opencode.json',
            json_encode(['instructions' => 'not-an-array']) . "\n",
        );
        $launcher = new OpenCodeAgentLauncher($this->makeProcessRunner(true));
        $launcher->prepareWorktree($worktree, $worktree . '/local/agent-context.md');

        $data = json_decode((string) file_get_contents($worktree . '/opencode.json'), true);
        if (($data['instructions'] ?? null) !== ['local/agent-context.md']) {
            echo "FAIL testPrepareWorktreeNormalizesNonArrayInstructions: unexpected instructions\n";

            return 1;
        }

        echo "OK testPrepareWorktreeNormalizesNonArrayInstructions\n";

        return 0;
    }

    private function testPrepareWorktreePreservesOtherKeys(): int
    {
        $worktree = $this->makeWorktree('preserve');
        file_put_contents(
            $worktree . '/opencode.json',
            json_encode(['model' => self::MODEL_NAME, 'theme' => 'dark', 'instructions' => []]) . "\n",
        );
        $launcher = new OpenCodeAgentLauncher($this->makeProcessRunner(true));
        $launcher->prepareWorktree($worktree, $worktree . '/local/agent-context.md');

        $data = json_decode((string) file_get_contents($worktree . '/opencode.json'), true);
        if (($data['model'] ?? null) !== self::MODEL_NAME || ($data['theme'] ?? null) !== 'dark') {
            echo "FAIL testPrepareWorktreePreservesOtherKeys: other keys lost\n";

            return 1;
        }

        echo "OK testPrepareWorktreePreservesOtherKeys\n";

        return 0;
    }

    private function testPrepareWorktreeAddsBacklogPermission(): int
    {
        $worktree = $this->makeWorktree('backlog-perm');
        $launcher = new OpenCodeAgentLauncher($this->makeProcessRunner(true), '/wp-root');
        $launcher->prepareWorktree($worktree, $worktree . '/local/agent-context.md');

        $data = json_decode((string) file_get_contents($worktree . '/opencode.json'), true);
        $pattern = BacklogPaths::directory('/wp-root') . '/**';
        $permission = $data['permission']['external_directory'][$pattern] ?? null;
        if ($permission !== 'allow') {
            echo "FAIL testPrepareWorktreeAddsBacklogPermission: expected allow permission for {$pattern}\n";

            return 1;
        }

        echo "OK testPrepareWorktreeAddsBacklogPermission\n";

        return 0;
    }

    private function testPrepareWorktreeIsBacklogPermissionIdempotent(): int
    {
        $worktree = $this->makeWorktree('backlog-perm-idempotent');
        $launcher = new OpenCodeAgentLauncher($this->makeProcessRunner(true), '/wp-root');
        $launcher->prepareWorktree($worktree, $worktree . '/local/agent-context.md');
        $launcher->prepareWorktree($worktree, $worktree . '/local/agent-context.md');

        $data = json_decode((string) file_get_contents($worktree . '/opencode.json'), true);
        $externalDirectory = $data['permission']['external_directory'] ?? [];
        $count = count(array_filter(array_keys($externalDirectory), static fn(int|string $k): bool => str_contains((string) $k, BacklogPaths::DIRECTORY)));
        if ($count !== 1) {
            echo "FAIL testPrepareWorktreeIsBacklogPermissionIdempotent: backlog permission duplicated (count={$count})\n";

            return 1;
        }

        echo "OK testPrepareWorktreeIsBacklogPermissionIdempotent\n";

        return 0;
    }

    private function testBuildLaunchCommandInitial(): int
    {
        $launcher = new OpenCodeAgentLauncher($this->makeProcessRunner(true));
        [$bin, $args] = $launcher->buildLaunchCommand('/worktree', '/ctx.md', AgentRole::DEVELOPER);

        if ($bin !== 'opencode' || $args !== ['--dangerously-skip-permissions']) {
            echo "FAIL testBuildLaunchCommandInitial: unexpected command\n";

            return 1;
        }

        echo "OK testBuildLaunchCommandInitial\n";

        return 0;
    }

    private function testBuildLaunchCommandInitialPrompt(): int
    {
        $launcher = new OpenCodeAgentLauncher($this->makeProcessRunner(true));
        [$bin, $args] = $launcher->buildLaunchCommand(
            '/worktree',
            '/ctx.md',
            AgentRole::DEVELOPER,
            null,
            false,
            null,
            'initial user prompt',
        );

        if ($bin !== 'opencode' || $args !== ['--dangerously-skip-permissions', '--prompt', 'initial user prompt']) {
            echo "FAIL testBuildLaunchCommandInitialPrompt: unexpected command\n";

            return 1;
        }

        echo "OK testBuildLaunchCommandInitialPrompt\n";

        return 0;
    }

    private function testBuildLaunchCommandContinue(): int
    {
        $launcher = new OpenCodeAgentLauncher($this->makeProcessRunner(true));
        [$bin, $args] = $launcher->buildLaunchCommand('/worktree', '/ctx.md', AgentRole::DEVELOPER, null, true);

        if ($bin !== 'opencode' || $args !== ['--dangerously-skip-permissions', '-c']) {
            echo "FAIL testBuildLaunchCommandContinue: expected permission flag + [-c]\n";

            return 1;
        }

        echo "OK testBuildLaunchCommandContinue\n";

        return 0;
    }

    private function testBuildLaunchCommandResumeId(): int
    {
        $launcher = new OpenCodeAgentLauncher($this->makeProcessRunner(true));
        [$bin, $args] = $launcher->buildLaunchCommand('/worktree', '/ctx.md', AgentRole::DEVELOPER, self::RESUME_SESSION_ID);

        if ($bin !== 'opencode' || $args !== ['--dangerously-skip-permissions', '-s', self::RESUME_SESSION_ID]) {
            echo "FAIL testBuildLaunchCommandResumeId: expected permission flag + [-s, session-abc]\n";

            return 1;
        }

        echo "OK testBuildLaunchCommandResumeId\n";

        return 0;
    }

    private function testBuildLaunchCommandIncludesPermissionFlags(): int
    {
        $launcher = new OpenCodeAgentLauncher($this->makeProcessRunner(true));

        foreach ([
            'initial' => $launcher->buildLaunchCommand('/worktree', '/ctx.md', AgentRole::DEVELOPER),
            'continue' => $launcher->buildLaunchCommand('/worktree', '/ctx.md', AgentRole::DEVELOPER, null, true),
            'resume' => $launcher->buildLaunchCommand('/worktree', '/ctx.md', AgentRole::DEVELOPER, 'session-x'),
        ] as $mode => [, $args]) {
            if (!in_array('--dangerously-skip-permissions', $args, true)) {
                echo "FAIL testBuildLaunchCommandIncludesPermissionFlags ($mode): --dangerously-skip-permissions missing\n";

                return 1;
            }
        }

        echo "OK testBuildLaunchCommandIncludesPermissionFlags\n";

        return 0;
    }

    private function testCaptureCurrentSessionIdParsesFirstRow(): int
    {
        $fakeOutput = implode("\n", [
            'ID             Title                Updated',
            'session-abc    My coding session    2026-01-01',
            'session-def    Another session      2026-01-02',
            '',
        ]);
        $runner = $this->makeProcessRunner(true, ['opencode session list -n 1' => $fakeOutput]);
        $launcher = new OpenCodeAgentLauncher($runner);

        if ($launcher->captureCurrentSessionId('/worktree') !== self::RESUME_SESSION_ID) {
            echo "FAIL testCaptureCurrentSessionIdParsesFirstRow: unexpected id\n";

            return 1;
        }

        echo "OK testCaptureCurrentSessionIdParsesFirstRow\n";

        return 0;
    }

    private function testCaptureCurrentSessionIdReturnsNullWhenEmpty(): int
    {
        $runner = $this->makeProcessRunner(true, []);
        $launcher = new OpenCodeAgentLauncher($runner);

        if ($launcher->captureCurrentSessionId('/worktree') !== null) {
            echo "FAIL testCaptureCurrentSessionIdReturnsNullWhenEmpty: expected null\n";

            return 1;
        }

        echo "OK testCaptureCurrentSessionIdReturnsNullWhenEmpty\n";

        return 0;
    }

    private function testListSessionsReturnsEmptyOnNullOutput(): int
    {
        $runner = $this->makeProcessRunner(true, []);
        $launcher = new OpenCodeAgentLauncher($runner);

        if ($launcher->listSessions('/worktree') !== []) {
            echo "FAIL testListSessionsReturnsEmptyOnNullOutput: expected empty array\n";

            return 1;
        }

        echo "OK testListSessionsReturnsEmptyOnNullOutput\n";

        return 0;
    }

    private function testListSessionsParsesRows(): int
    {
        $fakeOutput = implode("\n", [
            'ID             Title                Updated',
            'session-abc    My coding session    2026-01-01',
            'session-def    Another session      2026-01-02',
            '',
        ]);
        $runner = $this->makeProcessRunner(true, ['opencode session list -n 50' => $fakeOutput]);
        $launcher = new OpenCodeAgentLauncher($runner);

        $sessions = $launcher->listSessions('/worktree');
        if (count($sessions) !== 2) {
            echo 'FAIL testListSessionsParsesRows: expected 2 sessions, got ' . count($sessions) . "\n";

            return 1;
        }
        if ($sessions[0]->id !== self::RESUME_SESSION_ID || $sessions[1]->id !== 'session-def') {
            echo "FAIL testListSessionsParsesRows: unexpected session ids\n";

            return 1;
        }

        echo "OK testListSessionsParsesRows\n";

        return 0;
    }

    private function makeWorktree(string $name): string
    {
        $path = $this->tmpDir . '/worktrees/' . $name;
        mkdir($path, 0755, true);

        return $path;
    }

    /**
     * @param array<string, string> $outputs Command → stdout map (keyed by command string)
     */
    private function makeProcessRunner(bool $succeeds, array $outputs = []): ProcessRunner
    {
        return new FakeOpenCodeProcessRunner($succeeds, $outputs);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}

/**
 * Configurable ProcessRunner stub for OpenCodeAgentLauncher tests.
 */
final class FakeOpenCodeProcessRunner implements ProcessRunner
{
    /**
     * @var array<string, string>
     */
    private array $outputs;

    /**
     * @param bool                  $succeeds Result returned by succeeds()
     * @param array<string, string> $outputs  Command → stdout map
     */
    public function __construct(private bool $succeeds, array $outputs)
    {
        $this->outputs = $outputs;
    }

    /**
     * Returns the configured availability result.
     */
    public function succeeds(string $command): bool
    {
        return $this->succeeds;
    }

    /**
     * Returns the configured output for the given command, or null if not mapped.
     */
    public function output(string $command, string $cwd = ''): ?string
    {
        return $this->outputs[$command] ?? null;
    }
}
