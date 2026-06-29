<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Service;

use Sowapps\Backlog\Service\BoardService;
use Sowapps\Toolkit\Application\ProjectRootProvider;
use Sowapps\Toolkit\Client\Console\ConsoleClientInterface;

/**
 * Silent model for the isolated Doctrine migration-generation flow.
 *
 * Runs doctrine:migrations:diff against a temporary isolated database. The temporary database is
 * named {agentCode}_migrate_gen so that concurrent agents never share the same target. A per-agent
 * file lock prevents the same agent from running two concurrent generate operations.
 *
 * All database management (CREATE / DROP) uses PHP PDO connecting to localhost:5432. Doctrine
 * commands run locally via php backend/bin/console from the project root. No Docker or psql binary
 * is involved. If the local PHP/DB path is unavailable the operation fails immediately with a clear
 * structured error (thrown exception); there is no Docker fallback.
 *
 * This class is a silent thin model: it performs DB/board operations and returns data or throws,
 * but never formats progress for the terminal. Orchestration and display live in
 * {@see \Sowapps\SoManAgent\Script\Runner\GenerateMigrationRunner}.
 */
final class GenerateMigrationService
{
    private const LOCK_TIMEOUT_SECONDS = 30;
    private const PG_HOST = 'localhost';
    private const PG_PORT = '5432';

    public function __construct(
        private readonly ProjectRootProvider $rootProvider,
        private readonly BoardService $boardService,
        private readonly ConsoleClientInterface $consoleClient,
    ) {
    }

    /**
     * Resolves the agent code from the environment.
     *
     * The agent code names the temporary database, so it is mandatory.
     *
     * @throws \RuntimeException when AGENT_CODE is not set.
     */
    public function resolveAgentCode(): string
    {
        $fromEnv = trim((string) getenv('AGENT_CODE'));
        if ($fromEnv === '') {
            throw new \RuntimeException('AGENT_CODE is required for generate-migration.');
        }

        return $fromEnv;
    }

    /**
     * Returns the deterministic temp DB name for the given agent code.
     *
     * Special characters in the agent code are replaced with underscores so
     * the result is always a valid PostgreSQL identifier.
     */
    public static function buildTempDbName(string $agentCode): string
    {
        return preg_replace('/[^a-z0-9]/', '_', strtolower($agentCode)) . '_migrate_gen';
    }

    /**
     * Returns the absolute path to the per-agent lock file.
     */
    public function buildLockPath(string $agentCode): string
    {
        return $this->getProjectRoot() . '/local/tmp/generate-migration-' . $agentCode . '.lock';
    }

    /**
     * Returns the connection credentials parsed from the project root .env DATABASE_URL.
     *
     * @return array{scheme: string, user: string, password: string, query: string}
     */
    public function loadDatabaseCredentials(): array
    {
        return $this->parseDatabaseUrl();
    }

    /**
     * Returns the PHP DSN targeting the postgres system database on localhost:5432.
     */
    public function getSystemDsn(): string
    {
        return sprintf('pgsql:host=%s;port=%s;dbname=postgres', self::PG_HOST, self::PG_PORT);
    }

    /**
     * Returns the PostgreSQL host:port targeted by the flow.
     */
    public function getDbEndpoint(): string
    {
        return sprintf('%s:%s', self::PG_HOST, self::PG_PORT);
    }

    /**
     * Returns the project root used for lock files, .env and backend/.
     */
    public function getProjectRoot(): string
    {
        return $this->rootProvider->getCurrentProjectRoot();
    }

    /**
     * Verifies that PHP can connect to PostgreSQL on localhost:5432 before starting the flow.
     *
     * @param array{scheme: string, user: string, password: string, query: string} $credentials
     *
     * @throws \PDOException when the connection cannot be established.
     */
    public function assertLocalPrerequisites(array $credentials): void
    {
        new \PDO($this->getSystemDsn(), $credentials['user'], $credentials['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * Acquires an exclusive advisory file lock for this agent.
     *
     * Waits up to LOCK_TIMEOUT_SECONDS.
     *
     * @return resource
     * @throws \RuntimeException when the lock file cannot be opened or acquired within the window.
     */
    public function acquireLock(string $lockPath): mixed
    {
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new \RuntimeException("[lock] Cannot open lock file: {$lockPath}");
        }

        $deadline = time() + self::LOCK_TIMEOUT_SECONDS;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (time() >= $deadline) {
                fclose($handle);
                throw new \RuntimeException(
                    "[lock] Could not acquire per-agent lock within " . self::LOCK_TIMEOUT_SECONDS . "s: {$lockPath}"
                );
            }
            sleep(1);
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    public function releaseLock(mixed $handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * @param array{scheme: string, user: string, password: string, query: string} $credentials
     *
     * @throws \RuntimeException when the database cannot be dropped.
     */
    public function dropDatabase(string $dbName, array $credentials): void
    {
        try {
            $pdo = $this->openPostgresConnection($credentials);
            $pdo->exec(sprintf('DROP DATABASE IF EXISTS %s', $this->quoteIdent($dbName)));
        } catch (\PDOException $e) {
            throw new \RuntimeException("[initial cleanup] Could not drop database {$dbName}: " . $e->getMessage());
        }
    }

    /**
     * @param array{scheme: string, user: string, password: string, query: string} $credentials
     *
     * @throws \RuntimeException when the database cannot be created.
     */
    public function createDatabase(string $dbName, array $credentials): void
    {
        try {
            $pdo = $this->openPostgresConnection($credentials);
            $pdo->exec(sprintf('CREATE DATABASE %s', $this->quoteIdent($dbName)));
        } catch (\PDOException $e) {
            throw new \RuntimeException("[db creation] Could not create database {$dbName}: " . $e->getMessage());
        }
    }

    /**
     * Drops the temporary database without throwing (best-effort final cleanup).
     *
     * Returns null on success, or a human-readable warning message when the drop failed.
     *
     * @param array{scheme: string, user: string, password: string, query: string} $credentials
     */
    public function safeDropDatabase(string $dbName, array $credentials): ?string
    {
        try {
            $pdo = $this->openPostgresConnection($credentials);
            $pdo->exec(sprintf('DROP DATABASE IF EXISTS %s', $this->quoteIdent($dbName)));

            return null;
        } catch (\Throwable $e) {
            return "[final cleanup] Could not drop temp DB {$dbName}: " . $e->getMessage() . " — manual cleanup may be needed.";
        }
    }

    /**
     * Records the temporary database name on the active backlog entry (non-fatal).
     *
     * Returns true on success, false when the entry-ref could not be detected or the metadata write
     * did not succeed (the caller treats false as non-fatal).
     */
    public function recordDatabaseInBacklog(string $agentCode, string $dbName): bool
    {
        return $this->setBacklogDatabaseMeta($agentCode, $dbName);
    }

    /**
     * Clears the temporary database name from the active backlog entry (non-fatal).
     *
     * Returns true on success, false when the entry-ref could not be detected or the metadata write
     * did not succeed (the caller treats false as non-fatal).
     */
    public function clearDatabaseFromBacklog(string $agentCode): bool
    {
        return $this->setBacklogDatabaseMeta($agentCode, '');
    }

    /**
     * Builds a DATABASE_URL pointing to localhost:5432 with the given temporary database name.
     *
     * @param array{scheme: string, user: string, password: string, query: string} $credentials
     */
    public function buildTempDatabaseUrl(string $dbName, array $credentials): string
    {
        $url = sprintf(
            '%s://%s:%s@%s:%s/%s',
            $credentials['scheme'],
            rawurlencode($credentials['user']),
            rawurlencode($credentials['password']),
            self::PG_HOST,
            self::PG_PORT,
            rawurlencode($dbName),
        );

        if ($credentials['query'] !== '') {
            $url .= '?' . $credentials['query'];
        }

        return $url;
    }

    /**
     * Runs a Symfony console command from the project root with the given DATABASE_URL.
     *
     * Executed locally without Docker; requires PHP and the configured database to be available
     * on the local system.
     *
     * @param list<string> $extraArgs Additional CLI arguments appended after --no-interaction
     * @return int The process exit code.
     */
    public function runDoctrineCommand(string $subCommand, string $databaseUrl, array $extraArgs = []): int
    {
        $extra = implode(' ', array_map('escapeshellarg', $extraArgs));

        return $this->consoleClient->run(sprintf(
            'DATABASE_URL=%s php %s %s --no-interaction%s',
            escapeshellarg($databaseUrl),
            escapeshellarg($this->getProjectRoot() . '/backend/bin/console'),
            escapeshellarg($subCommand),
            $extra !== '' ? ' ' . $extra : '',
        ))->getExitCode();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Writes the database metadata value on the active backlog entry for the agent.
     *
     * Returns false (non-fatal) when the entry-ref cannot be detected or the metadata command fails.
     */
    private function setBacklogDatabaseMeta(string $agentCode, string $dbName): bool
    {
        $entryRef = $this->detectActiveEntryRef($agentCode);
        if ($entryRef === null) {
            return false;
        }

        // TODO Build string from code constants/enum
        $exitCode = $this->consoleClient->run(sprintf(
            'AGENT_ROLE=developer AGENT_CODE=%s php scripts/backlog/board.php entry-set-meta %s %s',
            escapeshellarg($agentCode),
            escapeshellarg($entryRef),
            escapeshellarg('database=' . $dbName),
        ))->getExitCode();

        return $exitCode === 0;
    }

    /**
     * Reads the backlog board and returns the entry-ref (feature or feature/task) for the
     * first in-progress entry assigned to the given agent.
     *
     * Returns null when the board file is missing or no matching entry is found.
     */
    private function detectActiveEntryRef(string $agentCode): ?string
    {
        $boardPath = $this->boardService->getBoardPath();
        if (!is_file($boardPath)) {
            return null;
        }

        $board   = $this->boardService->loadBoard($boardPath);
        $matches = $this->boardService->findActiveEntriesByAgent($board, $agentCode);

        if ($matches === []) {
            return null;
        }

        $entry   = $matches[0]->getEntry();
        $feature = $entry->getFeature();
        $task    = $entry->getTask();

        if ($feature === null) {
            return null;
        }

        return $task !== null ? "{$feature}/{$task}" : $feature;
    }

    /**
     * Parses DATABASE_URL from the project root .env and extracts connection credentials.
     *
     * Handles unquoted, double-quoted and single-quoted .env value formats.
     *
     * @return array{scheme: string, user: string, password: string, query: string}
     */
    private function parseDatabaseUrl(): array
    {
        $envFile = $this->getProjectRoot() . '/.env';
        $content = is_file($envFile) ? file_get_contents($envFile) : false;

        if ($content === false) {
            throw new \RuntimeException("[migrations] Cannot read .env file: {$envFile}");
        }

        if (preg_match('/^DATABASE_URL=("([^"]+)"|\'([^\']+)\'|([^\s]+))\s*$/m', $content, $matches) !== 1) {
            throw new \RuntimeException("[migrations] DATABASE_URL not found in .env");
        }

        $url    = ($matches[2] ?? '') !== '' ? $matches[2] : (($matches[3] ?? '') !== '' ? $matches[3] : ($matches[4] ?? ''));
        $parsed = parse_url($url);

        if ($parsed === false || !isset($parsed['scheme'], $parsed['user'])) {
            throw new \RuntimeException("[migrations] Cannot parse DATABASE_URL — expected postgresql://user:pass@host:port/dbname.");
        }

        return [
            'scheme'   => $parsed['scheme'],
            'user'     => $parsed['user'],
            'password' => $parsed['pass'] ?? '',
            'query'    => $parsed['query'] ?? '',
        ];
    }

    /**
     * Opens a PHP PDO connection to the postgres system database on localhost:5432.
     *
     * @param array{scheme: string, user: string, password: string, query: string} $credentials
     *
     * @throws \PDOException when the connection cannot be established.
     */
    private function openPostgresConnection(array $credentials): \PDO
    {
        return new \PDO($this->getSystemDsn(), $credentials['user'], $credentials['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * Quotes a PostgreSQL identifier with double quotes, escaping any embedded double quotes.
     */
    private function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
