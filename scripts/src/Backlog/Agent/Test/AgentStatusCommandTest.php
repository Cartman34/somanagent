<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Client\SessionDriverInterface;
use SoManAgent\Script\Backlog\Agent\Command\AgentStatusCommand;
use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\TextSlugger;
use Symfony\Component\Yaml\Yaml;

/**
 * Command-level tests for {@see AgentStatusCommand}.
 *
 * Covers: full session detail by `--code`, missing-code error, summary table
 * (always includes dead entries), and backlog/review derivation for both
 * developer and reviewer sessions.
 */
final class AgentStatusCommandTest
{
    private const CODE_ALIVE = 'd-alive';
    private const CODE_DEAD = 'd-dead';

    private string $tmpDir;

    /**
     * Creates temp directory for test fixtures.
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/backlog-agent-status-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    /**
     * Removes temp directory and all its contents.
     */
    public function __destruct()
    {
        $this->rmdir($this->tmpDir);
    }

    /**
     * Runs every test case and returns the cumulative number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testStatusByCodeShowsRecord();
        $failed += $this->testStatusByCodeRaisesWhenMissing();
        $failed += $this->testStatusAllShowsDeadEntries();
        $failed += $this->testStatusByCodeDerivesReviewerLabel();
        $failed += $this->testStatusByCodeDerivesManagerLabel();
        $failed += $this->testStatusByCodeDerivesManagerLabelWithMissingBoard();
        $failed += $this->testStatusAllDerivesManagerLabel();
        $failed += $this->testStatusByCodeIgnoresMissingBoard();

        return $failed;
    }

    private function testStatusByCodeShowsRecord(): int
    {
        $dir = $this->scratch('by-code');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d01', AgentRole::DEVELOPER, pid: getmypid() ?: 1));

        $boardPath = $dir . '/board.yaml';
        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'development',
                'feature' => 'payments-feature',
                'developer' => 'd01',
                'branch' => 'feat/payments-feature',
                'type' => 'feat',
            ],
        ]);

        $output = $this->captureHandle($this->buildCommand($service, $dir, $boardPath), ['code' => 'd01']);

        foreach (['Code      : d01', 'Role      : developer', 'Current   : payments-feature', 'running'] as $needle) {
            if (!str_contains($output, $needle)) {
                echo "FAIL testStatusByCodeShowsRecord: missing '{$needle}' in output\n{$output}\n";
                return 1;
            }
        }
        echo "OK testStatusByCodeShowsRecord\n";
        return 0;
    }

    private function testStatusByCodeRaisesWhenMissing(): int
    {
        $dir = $this->scratch('missing-code');
        $service = new AgentSessionService($dir);

        $cmd = $this->buildCommand($service, $dir, $dir . '/missing.md');

        $threw = false;
        try {
            $cmd->handle([], ['code' => 'd99']);
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), "No session found for code 'd99'");
        }

        if (!$threw) {
            echo "FAIL testStatusByCodeRaisesWhenMissing: expected explicit 'No session found' error\n";
            return 1;
        }
        echo "OK testStatusByCodeRaisesWhenMissing\n";
        return 0;
    }

    private function testStatusAllShowsDeadEntries(): int
    {
        $dir = $this->scratch('all-table');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession(self::CODE_ALIVE, AgentRole::DEVELOPER, pid: getmypid() ?: 1));
        $service->add($this->makeSession(self::CODE_DEAD, AgentRole::DEVELOPER, pid: 0));

        $output = $this->captureHandle($this->buildCommand($service, $dir, $dir . '/missing.md'), []);

        // statusAll() must behave like list --all: both alive and dead entries appear.
        if (!str_contains($output, self::CODE_ALIVE) || !str_contains($output, self::CODE_DEAD)) {
            echo "FAIL testStatusAllShowsDeadEntries: expected both sessions in output\n{$output}\n";
            return 1;
        }
        if (!str_contains($output, 'dead')) {
            echo "FAIL testStatusAllShowsDeadEntries: missing 'dead' label in PID column\n{$output}\n";
            return 1;
        }
        echo "OK testStatusAllShowsDeadEntries\n";
        return 0;
    }

    private function testStatusByCodeDerivesReviewerLabel(): int
    {
        $dir = $this->scratch('reviewer-label');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('r01', AgentRole::REVIEWER, pid: getmypid() ?: 1));

        $boardPath = $dir . '/board.yaml';
        $this->writeBoard($boardPath, [
            [
                'kind' => 'feature',
                'stage' => 'reviewing',
                'feature' => 'crypto-feature',
                'developer' => 'd04',
                'reviewer' => 'r01',
                'branch' => 'feat/crypto-feature',
                'type' => 'feat',
            ],
        ]);

        $output = $this->captureHandle($this->buildCommand($service, $dir, $boardPath), ['code' => 'r01']);

        if (!str_contains($output, 'Current   : [reviewing] crypto-feature')) {
            echo "FAIL testStatusByCodeDerivesReviewerLabel: missing reviewer-derived current label\n{$output}\n";
            return 1;
        }
        echo "OK testStatusByCodeDerivesReviewerLabel\n";
        return 0;
    }

    private function testStatusByCodeDerivesManagerLabel(): int
    {
        $dir = $this->scratch('manager-by-code');
        $service = new AgentSessionService($dir);
        $worktree = $this->tmpDir . '/wa-m01';
        $service->add(new AgentSession(
            code: 'm01',
            client: AgentClient::CLAUDE,
            role: AgentRole::MANAGER,
            pid: getmypid() ?: 1,
            worktree: $worktree,
            startedAt: new \DateTimeImmutable(),
            lastSeenAt: new \DateTimeImmutable(),
            sessionId: null,
        ));

        $boardPath = $dir . '/board.yaml';
        $this->writeBoard($boardPath, []);

        $output = $this->captureHandle($this->buildCommand($service, $dir, $boardPath), ['code' => 'm01']);

        if (!str_contains($output, 'Current   : manager ' . $worktree)) {
            echo "FAIL testStatusByCodeDerivesManagerLabel: expected 'manager {$worktree}' in output\n{$output}\n";
            return 1;
        }
        echo "OK testStatusByCodeDerivesManagerLabel\n";
        return 0;
    }

    private function testStatusByCodeDerivesManagerLabelWithMissingBoard(): int
    {
        $dir = $this->scratch('manager-no-board');
        $service = new AgentSessionService($dir);
        $worktree = $this->tmpDir . '/wa-m02';
        $service->add(new AgentSession(
            code: 'm02',
            client: AgentClient::CLAUDE,
            role: AgentRole::MANAGER,
            pid: getmypid() ?: 1,
            worktree: $worktree,
            startedAt: new \DateTimeImmutable(),
            lastSeenAt: new \DateTimeImmutable(),
            sessionId: null,
        ));

        $output = $this->captureHandle($this->buildCommand($service, $dir, $dir . '/missing.md'), ['code' => 'm02']);

        if (!str_contains($output, 'Current   : manager ' . $worktree)) {
            echo "FAIL testStatusByCodeDerivesManagerLabelWithMissingBoard: expected 'manager {$worktree}' even without board\n{$output}\n";
            return 1;
        }
        echo "OK testStatusByCodeDerivesManagerLabelWithMissingBoard\n";
        return 0;
    }

    private function testStatusAllDerivesManagerLabel(): int
    {
        $dir = $this->scratch('manager-all');
        $service = new AgentSessionService($dir);
        $worktree = $this->tmpDir . '/wa-m03';
        $service->add(new AgentSession(
            code: 'm03',
            client: AgentClient::CLAUDE,
            role: AgentRole::MANAGER,
            pid: getmypid() ?: 1,
            worktree: $worktree,
            startedAt: new \DateTimeImmutable(),
            lastSeenAt: new \DateTimeImmutable(),
            sessionId: null,
        ));

        $output = $this->captureHandle($this->buildCommand($service, $dir, $dir . '/missing.md'), []);

        if (!str_contains($output, 'manager ' . $worktree)) {
            echo "FAIL testStatusAllDerivesManagerLabel: expected 'manager {$worktree}' in summary table\n{$output}\n";
            return 1;
        }
        echo "OK testStatusAllDerivesManagerLabel\n";
        return 0;
    }

    private function testStatusByCodeIgnoresMissingBoard(): int
    {
        $dir = $this->scratch('missing-board');
        $service = new AgentSessionService($dir);
        $service->add($this->makeSession('d05', AgentRole::DEVELOPER, pid: getmypid() ?: 1));

        $output = $this->captureHandle($this->buildCommand($service, $dir, $dir . '/missing.md'), ['code' => 'd05']);

        // Without a board file, derivation falls back to "—" and the command still succeeds.
        if (!str_contains($output, 'Current   : —')) {
            echo "FAIL testStatusByCodeIgnoresMissingBoard: expected fallback dash for current\n{$output}\n";
            return 1;
        }
        echo "OK testStatusByCodeIgnoresMissingBoard\n";
        return 0;
    }

    private function buildCommand(AgentSessionService $service, string $projectRoot, string $boardPath): AgentStatusCommand
    {
        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);

        return new AgentStatusCommand(
            Console::getInstance(),
            $projectRoot,
            $boardPath,
            $service,
            $boardService,
            $this->defaultDriver(),
        );
    }

    /**
     * Returns a SessionDriverInterface that considers a session alive when its pid != 0.
     *
     * Preserves the convention used by makeSession(): pass getmypid() for alive, 0 for dead.
     */
    private function defaultDriver(): SessionDriverInterface
    {
        return new class implements SessionDriverInterface {
            /**
             * {@inheritdoc}
             */
            public function checkDependencies(): void {}

            /**
             * {@inheritdoc}
             */
            public function sessionExists(string $agentCode): bool
            {
                return false;
            }

            /**
             * {@inheritdoc}
             */
            public function allowsResumeWhileAlive(): bool
            {
                return false;
            }

            /**
             * {@inheritdoc}
             */
            public function launch(string $agentCode, AgentRole $role, AgentClient $client, string $bin, array $args, string $cwd, array $env, callable $onSpawned): int
            {
                return 0;
            }

            /**
             * {@inheritdoc}
             */
            public function resume(string $agentCode, AgentRole $role, AgentClient $client, string $bin, array $args, string $cwd, array $env, callable $onSpawned): int
            {
                return 0;
            }

            /**
             * {@inheritdoc}
             */
            public function stop(AgentSession $session): void {}

            /**
             * {@inheritdoc}
             */
            public function isAlive(AgentSession $session): bool
            {
                return $session->pid !== 0;
            }

            /**
             * {@inheritdoc}
             */
            public function isAttached(AgentSession $session): bool
            {
                return false;
            }

            /**
             * {@inheritdoc}
             *
             * @return list<string>
             */
            public function listLiveSessions(): array
            {
                return [];
            }

            /**
             * {@inheritdoc}
             */
            public function kill(string $agentCode): void {}

            /**
             * {@inheritdoc}
             */
            public function driverName(): string
            {
                return 'fake';
            }
        };
    }

    /**
     * @param array<string, bool|string|array<bool|string>> $options
     */
    private function captureHandle(AgentStatusCommand $command, array $options): string
    {
        ob_start();
        try {
            $command->handle([], $options);
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    private function makeSession(string $code, AgentRole $role, int $pid): AgentSession
    {
        $now = new \DateTimeImmutable();
        return new AgentSession(
            code: $code,
            client: AgentClient::CLAUDE,
            role: $role,
            pid: $pid,
            worktree: $this->tmpDir . '/wa-' . $code,
            startedAt: $now,
            lastSeenAt: $now,
            sessionId: null,
        );
    }

    /**
     * @param list<array<string, mixed>> $activeEntries
     */
    private function writeBoard(string $path, array $activeEntries): void
    {
        $order = ['kind', 'stage', 'feature', 'task', 'developer', 'reviewer', 'branch', 'feature-branch', 'base', 'pr', 'blocked', 'type'];
        $active = [];
        foreach ($activeEntries as $entry) {
            $item = [];
            foreach ($order as $key) {
                if (array_key_exists($key, $entry)) {
                    $item[$key] = $entry[$key];
                }
            }
            $item['title'] = $entry['title'] ?? ($entry['feature'] ?? '');
            $active[] = $item;
        }
        file_put_contents($path, Yaml::dump([
            'version' => 1,
            'todo' => [],
            'active' => $active,
        ], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
    }

    private function scratch(string $label): string
    {
        $path = $this->tmpDir . '/' . $label . '-' . uniqid('', true);
        mkdir($path, 0755, true);
        return $path;
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
