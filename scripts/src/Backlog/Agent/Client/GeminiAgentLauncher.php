<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Client;

use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Model\ResolvedModel;
use SoManAgent\Script\Backlog\Agent\Model\SessionInfo;
use SoManAgent\Script\Backlog\BacklogPaths;

/**
 * Launcher implementation for the Gemini CLI.
 *
 * Context injection: the GEMINI_SYSTEM_MD env var is set to the realpath of the
 * context file so the CLI picks it up as a system prompt on startup.
 */
final class GeminiAgentLauncher extends AbstractAgentClientLauncher
{
    /**
     * Permission flags injected at every launch to skip interactive approval prompts for the WA session.
     * auto_edit mirrors Claude's acceptEdits (approves edits without full bypass); --skip-trust suppresses
     * the one-time trust dialog for the current session only.
     */
    private const PERMISSION_FLAGS = ['--approval-mode', 'auto_edit', '--skip-trust'];

    private ProcessRunner $processRunner;
    private ?string $homeDir;

    /**
     * Absolute project root (WP path) used to whitelist `local/backlog/` via `--include-directories`.
     */
    private ?string $projectRoot;

    /**
     * @param ProcessRunner|null $processRunner Runner used to check availability and list sessions
     * @param string|null $homeDir Home directory containing the .gemini session store
     * @param string|null $projectRoot Project root; when set, adds `local/backlog/` to the Gemini sandbox via `--include-directories`
     */
    public function __construct(?ProcessRunner $processRunner = null, ?string $homeDir = null, ?string $projectRoot = null)
    {
        $this->processRunner = $processRunner ?? new ShellProcessRunner();
        $this->homeDir = $homeDir;
        $this->projectRoot = $projectRoot;
    }

    /**
     * {@inheritdoc}
     */
    public function client(): AgentClient
    {
        return AgentClient::GEMINI;
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        return $this->processRunner->succeeds('which gemini');
    }

    /**
     * {@inheritdoc}
     */
    public function requiredCliFlags(): array
    {
        return ['-r', '--model', '--prompt-interactive', '--approval-mode', '--skip-trust', '--include-directories'];
    }

    /**
     * {@inheritdoc}
     *
     * Adds GEMINI_SYSTEM_MD pointing to the context file so Gemini loads it as a system prompt.
     *
     * @param array<string, string> $baseEnv
     * @return array<string, string>
     */
    public function buildEnvironment(array $baseEnv, string $contextFilePath): array
    {
        $realPath = realpath($contextFilePath);

        return array_merge($baseEnv, ['GEMINI_SYSTEM_MD' => $realPath !== false ? $realPath : $contextFilePath]);
    }

    /**
     * {@inheritdoc}
     */
    public function buildLaunchCommand(
        string $worktree,
        string $contextFilePath,
        AgentRole $role,
        ?string $resumeSessionId = null,
        bool $continueLast = false,
        ?ResolvedModel $resolvedModel = null,
        ?string $initialPrompt = null,
    ): array {
        $backlogDirFlags = $this->projectRoot !== null
            ? ['--include-directories', BacklogPaths::directory($this->projectRoot)]
            : [];

        if ($resumeSessionId !== null) {
            return ['gemini', array_merge(self::PERMISSION_FLAGS, $backlogDirFlags, ['-r', $resumeSessionId])];
        }

        if ($continueLast) {
            return ['gemini', array_merge(self::PERMISSION_FLAGS, $backlogDirFlags, ['-r', 'latest'])];
        }

        $args = array_merge(self::PERMISSION_FLAGS, $backlogDirFlags, $resolvedModel !== null ? $resolvedModel->cliArgs : []);
        if ($initialPrompt !== null) {
            $args[] = '--prompt-interactive';
            $args[] = $initialPrompt;
        }

        return ['gemini', $args];
    }

    /**
     * {@inheritdoc}
     */
    public function captureCurrentSessionId(string $worktree): ?string
    {
        $sessions = $this->listSessions($worktree);

        return $sessions[0]->id ?? null;
    }

    /**
     * {@inheritdoc}
     *
     * Primary source: `gemini --list-sessions` run in the worktree directory.
     * Fallback: reads JSON session files from ~/.gemini/tmp/<project_hash>/.
     */
    public function listSessions(string $worktree): array
    {
        $output = $this->processRunner->output('gemini --list-sessions', $worktree);
        if ($output !== null) {
            return $this->parseListOutput($output);
        }

        return $this->listFromDirectory($worktree);
    }

    /**
     * @return list<SessionInfo>
     */
    private function parseListOutput(string $output): array
    {
        $output = trim($output);
        if ($output === '') {
            return [];
        }

        $decoded = json_decode($output, true);
        if (is_array($decoded)) {
            return $this->parseJsonSessions($decoded);
        }

        return $this->parseTableSessions($output);
    }

    /**
     * @param array<mixed> $data
     * @return list<SessionInfo>
     */
    private function parseJsonSessions(array $data): array
    {
        // Accept both a root array and a wrapped {"sessions": [...]} envelope
        /** @var array<mixed> $items */
        $items = array_is_list($data) ? $data : ($data['sessions'] ?? $data['data'] ?? []);
        $sessions = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string) ($item['id'] ?? $item['sessionId'] ?? $item['session_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $sessions[] = new SessionInfo(
                $id,
                $this->parseTimestamp($item['created_at'] ?? $item['started_at'] ?? $item['createdAt'] ?? null),
                $this->parseTimestamp($item['last_seen_at'] ?? $item['updated_at'] ?? $item['lastSeenAt'] ?? null),
                isset($item['message_count']) && is_int($item['message_count']) ? $item['message_count'] : null,
                null,
            );
        }

        return $sessions;
    }

    /**
     * @return list<SessionInfo>
     */
    private function parseTableSessions(string $output): array
    {
        $sessions = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, 'Session') || str_starts_with($line, '#') || str_starts_with($line, '-')) {
                continue;
            }
            // First whitespace-delimited token is the session ID
            $parts = preg_split('/\s+/', $line, 2);
            if ($parts === false || $parts[0] === '') {
                continue;
            }
            $sessions[] = new SessionInfo($parts[0], null, null, null, null);
        }

        return $sessions;
    }

    /**
     * @return list<SessionInfo>
     */
    private function listFromDirectory(string $worktree): array
    {
        $dir = $this->geminiSessionDir($worktree);
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.json') ?: [];
        $sessions = [];
        foreach ($files as $file) {
            $sessions[] = $this->parseSessionFile($file);
        }

        usort($sessions, static function (SessionInfo $a, SessionInfo $b): int {
            $aTime = $a->lastMessageAt?->getTimestamp() ?? $a->startedAt?->getTimestamp() ?? 0;
            $bTime = $b->lastMessageAt?->getTimestamp() ?? $b->startedAt?->getTimestamp() ?? 0;

            return $bTime <=> $aTime;
        });

        return $sessions;
    }

    private function parseSessionFile(string $file): SessionInfo
    {
        $id = pathinfo($file, PATHINFO_FILENAME) ?: basename($file, '.json');
        $content = file_get_contents($file);
        if ($content === false) {
            return new SessionInfo($id, null, null, null, null);
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return new SessionInfo($id, null, null, null, null);
        }

        return new SessionInfo(
            $id,
            $this->parseTimestamp($data['created_at'] ?? $data['started_at'] ?? null),
            $this->parseTimestamp($data['last_seen_at'] ?? $data['updated_at'] ?? null),
            isset($data['message_count']) && is_int($data['message_count']) ? $data['message_count'] : null,
            null,
        );
    }

    private function geminiSessionDir(string $worktree): string
    {
        $projectHash = $this->projectHash($worktree);

        return rtrim($this->homeDir(), '/') . '/.gemini/tmp/' . $projectHash;
    }

    private function projectHash(string $worktree): string
    {
        $path = realpath($worktree) ?: $worktree;

        return hash('sha256', $path);
    }

    private function homeDir(): string
    {
        if ($this->homeDir !== null) {
            return $this->homeDir;
        }

        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return $home;
        }

        return '';
    }

    private function parseTimestamp(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
