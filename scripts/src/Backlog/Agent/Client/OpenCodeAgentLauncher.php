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
 * Launcher implementation for OpenCode.
 *
 * Context is injected via opencode.json `instructions` array so the framework
 * chdir into the worktree is the only positional requirement at launch time.
 * Session listing delegates to `opencode session list`.
 */
final class OpenCodeAgentLauncher extends AbstractAgentClientLauncher
{
    private const CONTEXT_INSTRUCTION = 'local/agent-context.md';
    private const OPENCODE_JSON = 'opencode.json';
    private const SESSION_LIST_MAX = 50;

    private ProcessRunner $processRunner;

    /**
     * @param ProcessRunner|null $processRunner Runner used for binary checks and session listing
     */
    public function __construct(?ProcessRunner $processRunner = null)
    {
        $this->processRunner = $processRunner ?? new ShellProcessRunner();
    }

    /**
     * {@inheritdoc}
     */
    public function client(): AgentClient
    {
        return AgentClient::OPENCODE;
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        return $this->processRunner->succeeds('which opencode');
    }

    /**
     * {@inheritdoc}
     */
    public function requiredCliFlags(): array
    {
        return ['-s', '-c', '--model'];
    }

    /**
     * Idempotently adds `local/agent-context.md` to the `instructions` array of
     * `<worktree>/opencode.json`, preserving all other keys. Creates a minimal file
     * when none exists.
     */
    public function prepareWorktree(string $worktree, string $contextFilePath): void
    {
        $configPath = rtrim($worktree, '/') . '/' . self::OPENCODE_JSON;

        $data = [];
        if (is_file($configPath)) {
            $raw = file_get_contents($configPath);
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }

        $instructions = $data['instructions'] ?? [];
        if (!is_array($instructions)) {
            $instructions = [];
        }

        if (!in_array(self::CONTEXT_INSTRUCTION, $instructions, true)) {
            $instructions[] = self::CONTEXT_INSTRUCTION;
        }

        $data['instructions'] = array_values($instructions);

        file_put_contents($configPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
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
    ): array {
        if ($resumeSessionId !== null) {
            return ['opencode', ['-s', $resumeSessionId]];
        }
        if ($continueLast) {
            return ['opencode', ['-c']];
        }

        return ['opencode', $resolvedModel !== null ? $resolvedModel->cliArgs : []];
    }

    /**
     * {@inheritdoc}
     */
    public function captureCurrentSessionId(string $worktree): ?string
    {
        $output = $this->processRunner->output('opencode session list -n 1', $worktree);
        if ($output === null || trim($output) === '') {
            return null;
        }

        return $this->parseFirstSessionId($output);
    }

    /**
     * {@inheritdoc}
     */
    public function listSessions(string $worktree): array
    {
        $output = $this->processRunner->output(
            sprintf('opencode session list -n %d', self::SESSION_LIST_MAX),
            $worktree,
        );
        if ($output === null || trim($output) === '') {
            return [];
        }

        return $this->parseSessionRows($output);
    }

    /**
     * Extracts the session id from the first data row of `opencode session list` output.
     *
     * The first non-empty line is treated as a header and skipped; the id is the
     * first whitespace-delimited token of the following line.
     */
    private function parseFirstSessionId(string $output): ?string
    {
        $nonEmpty = $this->nonEmptyLines($output);
        // index 0 = header, index 1 = first data row
        if (count($nonEmpty) < 2) {
            return null;
        }
        $tokens = preg_split('/\s+/', $nonEmpty[1], -1, PREG_SPLIT_NO_EMPTY);

        return ($tokens !== false && isset($tokens[0])) ? $tokens[0] : null;
    }

    /**
     * Parses all data rows of `opencode session list` output into SessionInfo objects.
     *
     * The first non-empty line is the header and is skipped. Each subsequent line
     * yields one SessionInfo with the session id as the first whitespace-delimited token.
     *
     * @return list<SessionInfo>
     */
    private function parseSessionRows(string $output): array
    {
        $sessions = [];
        // skip header (first non-empty line)
        foreach (array_slice($this->nonEmptyLines($output), 1) as $line) {
            $tokens = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
            if ($tokens === false || $tokens === []) {
                continue;
            }
            $sessions[] = new SessionInfo($tokens[0], null, null, null, null);
        }

        return $sessions;
    }

    /**
     * Returns all trimmed non-empty lines from the given output, re-indexed from 0.
     *
     * @return list<string>
     */
    private function nonEmptyLines(string $output): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $output)),
            static fn(string $l): bool => $l !== '',
        ));
    }
}
