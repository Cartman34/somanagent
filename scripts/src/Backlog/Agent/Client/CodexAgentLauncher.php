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
 * Launcher implementation for Codex CLI.
 */
final class CodexAgentLauncher extends AbstractAgentClientLauncher
{
    private const FIRST_PROMPT_LENGTH = 80;
    private const SESSION_PROMPT_SUFFIX = "\n\n--- Begin session ---";

    /**
     * Approval flags injected at every launch to suppress interactive prompts within the WA session.
     * Combined with sandbox_mode=workspace-write from the user's ~/.codex/config.toml, this keeps
     * filesystem writes safe while removing the approval dialog.
     */
    private const APPROVAL_FLAGS = ['--ask-for-approval', 'never'];

    /**
     * Runner used to check whether the Codex and zstd binaries are available locally.
     */
    private ProcessRunner $processRunner;

    /**
     * Optional home directory override used to locate the Codex rollout session store in tests.
     */
    private ?string $homeDir;

    /**
     * Warning output hook used when a compressed Codex rollout cannot be read.
     *
     * @var callable(string): void
     */
    private $warningWriter;

    /**
     * Absolute project root (WP path) used to whitelist `local/backlog/` in the Codex sandbox.
     */
    private ?string $projectRoot;

    /**
     * @param ProcessRunner|null $processRunner Runner used to check local binary availability
     * @param string|null $homeDir Home directory containing the .codex/sessions rollout store
     * @param callable(string): void|null $warningWriter Warning output hook for skipped compressed rollouts
     * @param string|null $projectRoot Project root; when set, adds `local/backlog/` to the Codex sandbox whitelist
     */
    public function __construct(?ProcessRunner $processRunner = null, ?string $homeDir = null, ?callable $warningWriter = null, ?string $projectRoot = null)
    {
        $this->processRunner = $processRunner ?? new ShellProcessRunner();
        $this->homeDir = $homeDir;
        $this->warningWriter = $warningWriter ?? static function (string $message): void {
            fwrite(STDERR, $message);
        };
        $this->projectRoot = $projectRoot;
    }

    /**
     * {@inheritdoc}
     */
    public function client(): AgentClient
    {
        return AgentClient::CODEX;
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        return $this->processRunner->succeeds('which codex');
    }

    /**
     * {@inheritdoc}
     */
    public function requiredCliFlags(): array
    {
        return ['-C', '--last', '--model', '--config', '--ask-for-approval', '--add-dir'];
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
        $args = ['-C', $worktree, ...self::APPROVAL_FLAGS];

        if ($this->projectRoot !== null) {
            $args[] = '--add-dir';
            $args[] = BacklogPaths::directory($this->projectRoot);
        }

        if ($resumeSessionId !== null) {
            $args[] = 'resume';
            $args[] = $resumeSessionId;

            return ['codex', $args];
        }

        if ($continueLast) {
            $args[] = 'resume';
            $args[] = '--last';

            return ['codex', $args];
        }

        if ($resolvedModel !== null) {
            $args = array_merge($args, $resolvedModel->cliArgs);
        }

        if (!is_readable($contextFilePath)) {
            throw new \RuntimeException(sprintf('Unable to read agent context file: %s', $contextFilePath));
        }
        $context = file_get_contents($contextFilePath);
        if ($context === false) {
            throw new \RuntimeException(sprintf('Unable to read agent context file: %s', $contextFilePath));
        }

        $prompt = $context . self::SESSION_PROMPT_SUFFIX;
        if ($initialPrompt !== null) {
            $prompt .= "\n\n" . $initialPrompt;
        }

        $args[] = $prompt;

        return ['codex', $args];
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
     */
    public function listSessions(string $worktree): array
    {
        $sessions = [];
        $canonicalWorktree = $this->canonicalPath($worktree);
        foreach ($this->sessionFiles() as $file) {
            $session = $this->parseSessionFile($file, $canonicalWorktree);
            if ($session !== null) {
                $sessions[] = $session;
            }
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
    private function sessionFiles(): array
    {
        $dir = $this->codexSessionsDir();
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                continue;
            }
            $path = $item->getPathname();
            if (preg_match('/\/rollout-.+\.jsonl(?:\.zst)?$/', $path) === 1) {
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }

    private function codexSessionsDir(): string
    {
        return rtrim($this->homeDir(), '/') . '/.codex/sessions';
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

    private function canonicalPath(string $path): string
    {
        return str_replace('\\', '/', realpath($path) ?: $path);
    }

    private function parseSessionFile(string $file, string $worktree): ?SessionInfo
    {
        $handle = $this->openSessionFile($file);
        if ($handle === null) {
            return null;
        }

        $id = $this->fallbackId($file);
        $cwd = null;
        $startedAt = null;
        $lastMessageAt = null;
        $messageCount = 0;
        $firstPromptExcerpt = null;

        while (($line = fgets($handle)) !== false) {
            $data = json_decode(trim($line), true);
            if (!is_array($data)) {
                continue;
            }

            if (($data['type'] ?? null) === 'session_meta') {
                $payload = $data['payload'] ?? null;
                if (is_array($payload)) {
                    $id = is_string($payload['id'] ?? null) ? $payload['id'] : $id;
                    $cwd = is_string($payload['cwd'] ?? null) ? $this->canonicalPath($payload['cwd']) : $cwd;
                    $startedAt ??= $this->parseTimestamp($payload['timestamp'] ?? null);
                }
            }

            $timestamp = $this->parseTimestamp($data['timestamp'] ?? null);
            if ($timestamp !== null) {
                $startedAt ??= $timestamp;
                if ($lastMessageAt === null || $timestamp > $lastMessageAt) {
                    $lastMessageAt = $timestamp;
                }
            }

            if ($this->isConversationMessage($data)) {
                $messageCount++;
            }
            if ($firstPromptExcerpt === null && $this->isUserMessage($data)) {
                $firstPromptExcerpt = $this->extractPromptExcerpt($data);
            }
        }
        $this->closeSessionFile($handle, $file);

        if ($cwd !== $worktree) {
            return null;
        }

        return new SessionInfo($id, $startedAt, $lastMessageAt, $messageCount, $firstPromptExcerpt);
    }

    /**
     * @return resource|null
     */
    private function openSessionFile(string $file): mixed
    {
        if (!str_ends_with($file, '.zst')) {
            $handle = fopen($file, 'rb');

            return $handle === false ? null : $handle;
        }

        if (!$this->processRunner->succeeds('which zstd')) {
            ($this->warningWriter)(sprintf("Warning: skipping compressed Codex rollout without zstd: %s\n", $file));

            return null;
        }

        $handle = popen('zstd -dc ' . escapeshellarg($file), 'r');

        return $handle === false ? null : $handle;
    }

    /**
     * @param resource $handle
     */
    private function closeSessionFile(mixed $handle, string $file): void
    {
        if (str_ends_with($file, '.zst')) {
            pclose($handle);

            return;
        }

        fclose($handle);
    }

    private function fallbackId(string $file): string
    {
        $name = basename($file);
        $name = preg_replace('/\.jsonl(?:\.zst)?$/', '', $name) ?? $name;

        return str_starts_with($name, 'rollout-') ? substr($name, 8) : $name;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isConversationMessage(array $data): bool
    {
        $payload = $data['payload'] ?? null;
        if (!is_array($payload) || ($payload['type'] ?? null) !== 'message') {
            return false;
        }

        return in_array($payload['role'] ?? null, ['user', 'assistant'], true);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isUserMessage(array $data): bool
    {
        $payload = $data['payload'] ?? null;

        return is_array($payload) && ($payload['type'] ?? null) === 'message' && ($payload['role'] ?? null) === 'user';
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
        $payload = $data['payload'] ?? null;
        if (!is_array($payload)) {
            return null;
        }

        $text = $this->extractText($payload['content'] ?? null);
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
            if (is_array($item) && in_array($item['type'] ?? null, ['input_text', 'output_text', 'text'], true) && is_string($item['text'] ?? null)) {
                $parts[] = $item['text'];
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }
}
