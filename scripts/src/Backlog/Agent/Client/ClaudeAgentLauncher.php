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

/**
 * Launcher implementation for Claude Code.
 */
final class ClaudeAgentLauncher extends AbstractAgentClientLauncher
{
    private const FIRST_PROMPT_LENGTH = 80;

    /**
     * Permission flags injected at every launch to skip interactive approval prompts within the WA session.
     * Scoped to the CLI session; does not modify ~/.claude.json or any global config.
     */
    private const PERMISSION_FLAGS = ['--permission-mode', 'acceptEdits'];

    private ProcessRunner $processRunner;
    private ?string $homeDir;

    /**
     * @param ProcessRunner|null $processRunner Runner used to check the local Claude binary availability
     * @param string|null $homeDir Home directory containing the .claude/projects session store
     */
    public function __construct(?ProcessRunner $processRunner = null, ?string $homeDir = null)
    {
        $this->processRunner = $processRunner ?? new ShellProcessRunner();
        $this->homeDir = $homeDir;
    }

    /**
     * {@inheritdoc}
     */
    public function client(): AgentClient
    {
        return AgentClient::CLAUDE;
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        return $this->processRunner->succeeds('which claude');
    }

    /**
     * {@inheritdoc}
     */
    public function requiredCliFlags(): array
    {
        return ['--append-system-prompt', '--resume', '--continue', '--model', '--effort', '--permission-mode'];
    }

    /**
     * {@inheritdoc}
     *
     * The working directory is positioned by the caller before launch (tmux `-c`
     * for TmuxSessionDriver, `proc_open` cwd for DirectSessionDriver, and an
     * explicit `chdir()` in AgentStartCommand). The `claude` binary itself does
     * not accept a working-directory flag in v2.x, so $worktree is intentionally
     * not passed on the command line.
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
        if (!is_readable($contextFilePath)) {
            throw new \RuntimeException(sprintf('Unable to read agent context file: %s', $contextFilePath));
        }
        $context = file_get_contents($contextFilePath);
        if ($context === false) {
            throw new \RuntimeException(sprintf('Unable to read agent context file: %s', $contextFilePath));
        }

        $args = [
            '--append-system-prompt',
            $context,
            ...self::PERMISSION_FLAGS,
        ];

        if ($resolvedModel !== null) {
            $args = array_merge($args, $resolvedModel->cliArgs);
        }

        if ($resumeSessionId !== null) {
            $args[] = '--resume';
            $args[] = $resumeSessionId;
        } elseif ($continueLast) {
            $args[] = '--continue';
        } elseif ($initialPrompt !== null) {
            $args[] = $initialPrompt;
        }

        return ['claude', $args];
    }

    /**
     * {@inheritdoc}
     */
    public function captureCurrentSessionId(string $worktree): ?string
    {
        $files = $this->sessionFiles($worktree);
        if ($files === []) {
            return null;
        }

        usort($files, fn(string $a, string $b): int => $this->fileTimestamp($b) <=> $this->fileTimestamp($a));

        return pathinfo($files[0], PATHINFO_FILENAME) ?: null;
    }

    /**
     * {@inheritdoc}
     */
    public function listSessions(string $worktree): array
    {
        $sessions = [];
        foreach ($this->sessionFiles($worktree) as $file) {
            $sessions[] = $this->parseSessionFile($file);
        }

        usort($sessions, static function (SessionInfo $a, SessionInfo $b): int {
            $aTime = $a->lastMessageAt?->getTimestamp() ?? $a->startedAt?->getTimestamp() ?? 0;
            $bTime = $b->lastMessageAt?->getTimestamp() ?? $b->startedAt?->getTimestamp() ?? 0;

            return $bTime <=> $aTime;
        });

        return $sessions;
    }

    /**
     * @return list<string>
     */
    private function sessionFiles(string $worktree): array
    {
        $dir = $this->claudeProjectDir($worktree);
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.jsonl') ?: [];
        sort($files);

        return $files;
    }

    private function claudeProjectDir(string $worktree): string
    {
        return rtrim($this->homeDir(), '/') . '/.claude/projects/' . $this->encodeWorktree($worktree);
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

    private function encodeWorktree(string $worktree): string
    {
        $path = realpath($worktree) ?: $worktree;
        $path = str_replace('\\', '/', $path);

        return str_replace('/', '-', $path);
    }

    private function parseSessionFile(string $file): SessionInfo
    {
        $id = pathinfo($file, PATHINFO_FILENAME) ?: basename($file, '.jsonl');
        $startedAt = null;
        $lastMessageAt = null;
        $messageCount = 0;
        $firstPromptExcerpt = null;

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return new SessionInfo($id, null, null, null, null);
        }

        while (($line = fgets($handle)) !== false) {
            $data = json_decode(trim($line), true);
            if (!is_array($data)) {
                continue;
            }

            if ($this->isConversationMessage($data)) {
                $messageCount++;
            }
            $timestamp = $this->parseTimestamp($data['timestamp'] ?? null);
            if ($timestamp !== null) {
                $startedAt ??= $timestamp;
                if ($lastMessageAt === null || $timestamp > $lastMessageAt) {
                    $lastMessageAt = $timestamp;
                }
            }

            if ($firstPromptExcerpt === null && ($data['type'] ?? null) === 'user') {
                $firstPromptExcerpt = $this->extractPromptExcerpt($data);
            }
        }
        fclose($handle);

        return new SessionInfo($id, $startedAt, $lastMessageAt, $messageCount, $firstPromptExcerpt);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isConversationMessage(array $data): bool
    {
        $message = $data['message'] ?? null;
        if (!is_array($message)) {
            return false;
        }

        return in_array($message['role'] ?? null, ['user', 'assistant'], true);
    }

    private function fileTimestamp(string $file): int
    {
        $timestamp = filemtime($file);

        return $timestamp === false ? 0 : $timestamp;
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

    /**
     * @param array<string, mixed> $data
     */
    private function extractPromptExcerpt(array $data): ?string
    {
        $message = $data['message'] ?? null;
        if (is_array($message)) {
            $text = $this->extractText($message['content'] ?? null);
        } else {
            $text = $this->extractText($data['content'] ?? null);
        }

        if ($text === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($text === '') {
            return null;
        }

        return strlen($text) > self::FIRST_PROMPT_LENGTH
            ? substr($text, 0, self::FIRST_PROMPT_LENGTH)
            : $text;
    }

    private function extractText(mixed $content): ?string
    {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return null;
        }

        $parts = [];
        foreach ($content as $item) {
            if (is_string($item)) {
                $parts[] = $item;
                continue;
            }
            if (is_array($item) && ($item['type'] ?? null) === 'text' && is_string($item['text'] ?? null)) {
                $parts[] = $item['text'];
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }
}
