<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Runner;

use Sowapps\Toolkit\Console;
use Sowapps\SoManAgent\Script\SoManAgentApplication;
use Sowapps\Backlog\Service\BacklogBoardService;
use Sowapps\Toolkit\TextSlugger;
use Sowapps\Toolkit\Client\FilesystemClient;

/**
 * Runs doctrine:migrations:diff against a temporary isolated database.
 *
 * The temporary database is named {agentCode}_migrate_gen so that concurrent
 * agents never share the same target. A per-agent file lock prevents the same
 * agent from running two concurrent generate operations.
 *
 * All database management (CREATE / DROP) uses PHP PDO connecting to localhost:5432.
 * Doctrine commands run locally via php bin/console from the project's backend/ directory.
 * No Docker or psql binary is involved. If the local PHP/DB path is unavailable the command
 * fails immediately with a clear structured error; there is no Docker fallback.
 *
 * Steps (each step name appears in the error when it fails):
 *   1. agent detection   — resolve the agent code from the WA path or env
 *   2. prerequisites     — verify PHP can connect to PostgreSQL on localhost:5432
 *   3. lock              — acquire the per-agent advisory lock
 *   4. initial cleanup   — drop any leftover temporary database from a prior run
 *   5. db creation       — create the temporary database
 *   6. backlog meta      — record the temp DB name on the active backlog entry (non-fatal)
 *   7. migrations        — apply existing migrations on the temporary database
 *   8. doctrine diff     — run doctrine:migrations:diff against the temporary database
 *   9. final cleanup     — drop the temporary database, clear backlog meta, and release the lock
 */
final class MigrateGenerateService
{
    private const LOCK_TIMEOUT_SECONDS = 30;
    private const PG_HOST = 'localhost';
    private const PG_PORT = '5432';

    private readonly Console $console;

    /**
     * @param SoManAgentApplication $app
     * @param string $agentCode Resolved agent code (e.g. "d04")
     * @param string $projectRoot Absolute path to the project root (WA or WP), used for lock files, .env and backend/
     * @param string $boardRoot Absolute path to the WP root where the canonical backlog board lives
     */
    public function __construct(
        private readonly SoManAgentApplication $app,
        private readonly string $agentCode,
        private readonly string $projectRoot,
        private readonly string $boardRoot,
    ) {
        $this->console = $app->console;
    }

    /**
     * Runs the full isolated migration-generation flow.
     *
     * Returns 0 on success. Exits via console->fail() on unrecoverable error.
     */
    public function run(): int
    {
        $dbName   = $this->buildTempDbName($this->agentCode);
        $lockPath = $this->projectRoot . '/local/tmp/migrate-gen-' . $this->agentCode . '.lock';

        $this->console->info("Agent: {$this->agentCode}");
        $this->console->info("Temp DB: {$dbName}");

        $credentials = $this->parseDatabaseUrl();

        // ── Step: prerequisites ───────────────────────────────────────────────
        $this->console->step('Checking prerequisites — PHP connection to local PostgreSQL');
        $this->assertLocalPrerequisites($credentials);

        // ── Step: lock ────────────────────────────────────────────────────────
        $this->console->step('Acquiring per-agent lock');
        $lockHandle = $this->acquireLock($lockPath);

        try {
            // ── Step: initial cleanup ─────────────────────────────────────────
            $this->console->step('Initial cleanup — dropping leftover temp DB');
            $this->dropDatabase($dbName, $credentials);

            // ── Step: db creation ─────────────────────────────────────────────
            $this->console->step('Creating temp DB');
            $this->createDatabase($dbName, $credentials);

            try {
                // ── Step: record in backlog meta ──────────────────────────────
                $this->recordDatabaseInBacklog($dbName);

                $databaseUrl = $this->buildTempDatabaseUrl($dbName, $credentials);
                $this->console->info(sprintf('DB host: %s:%s', self::PG_HOST, self::PG_PORT));

                // ── Step: migrations ──────────────────────────────────────────
                $this->console->step('Applying existing migrations on temp DB');
                $code = $this->runDoctrineCommand('doctrine:migrations:migrate', $databaseUrl);
                if ($code !== 0) {
                    throw new \RuntimeException("[migrations] doctrine:migrations:migrate failed (exit {$code}).");
                }

                // ── Step: doctrine diff ───────────────────────────────────────
                $this->console->step('Generating migration diff');
                $code = $this->runDoctrineCommand('doctrine:migrations:diff', $databaseUrl, ['--allow-empty-diff']);
                if ($code !== 0) {
                    throw new \RuntimeException("[doctrine diff] doctrine:migrations:diff failed (exit {$code}).");
                }
            } finally {
                // ── Step: final cleanup ───────────────────────────────────────
                $this->console->step('Final cleanup — dropping temp DB');
                $this->safeDropDatabase($dbName, $credentials);
                try {
                    $this->clearDatabaseFromBacklog();
                } catch (\Throwable $e) {
                    $this->console->warn("[final cleanup] Error while clearing backlog meta: " . $e->getMessage());
                }
            }
        } finally {
            $this->releaseLock($lockHandle, $lockPath);
        }

        $this->console->ok("Migration diff generated successfully.");

        return 0;
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

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Verifies that PHP can connect to PostgreSQL on localhost:5432 before starting the flow.
     *
     * Exits via console->fail() with a structured error (PHP DSN, working directory, cause,
     * and action) when the connection cannot be established.
     *
     * @param array{scheme: string, user: string, password: string, query: string} $credentials
     */
    private function assertLocalPrerequisites(array $credentials): void
    {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', self::PG_HOST, self::PG_PORT);
        try {
            new \PDO($dsn, $credentials['user'], $credentials['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException $e) {
            $this->console->fail(implode("\n", [
                '[prerequisites] Cannot connect to PostgreSQL on ' . self::PG_HOST . ':' . self::PG_PORT . '.',
                '  PHP DSN: ' . $dsn,
                '  Working directory: ' . $this->projectRoot,
                '  Cause: ' . $e->getMessage(),
                '  Action: ensure the Docker PostgreSQL service is running and accessible on localhost:5432 (e.g. run docker compose up -d db from WP).',
            ]));
        }
        $this->console->ok('PHP connected to local PostgreSQL successfully.');
    }

    /**
     * Acquires an exclusive advisory file lock for this agent.
     *
     * Waits up to LOCK_TIMEOUT_SECONDS and exits with an error when the lock
     * cannot be acquired within that window.
     *
     * @return resource
     */
    private function acquireLock(string $lockPath): mixed
    {
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            $this->console->fail("[lock] Cannot open lock file: {$lockPath}");
        }

        $deadline = time() + self::LOCK_TIMEOUT_SECONDS;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (time() >= $deadline) {
                fclose($handle);
                $this->console->fail(
                    "[lock] Could not acquire per-agent lock within " . self::LOCK_TIMEOUT_SECONDS . "s: {$lockPath}"
                );
            }
            sleep(1);
        }

        $this->console->ok("Lock acquired.");

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function releaseLock(mixed $handle, string $lockPath): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * @param array{scheme: string, user: string, password: string, query: string} $credentials
     */
    private function dropDatabase(string $dbName, array $credentials): void
    {
        try {
            $pdo = $this->openPostgresConnection($credentials);
            $pdo->exec(sprintf('DROP DATABASE IF EXISTS %s', $this->quoteIdent($dbName)));
        } catch (\PDOException $e) {
            throw new \RuntimeException("[initial cleanup] Could not drop database {$dbName}: " . $e->getMessage());
        }
        $this->console->ok("Old temp DB removed (if existed).");
    }

    /**
     * @param array{scheme: string, user: string, password: string, query: string} $credentials
     */
    private function createDatabase(string $dbName, array $credentials): void
    {
        try {
            $pdo = $this->openPostgresConnection($credentials);
            $pdo->exec(sprintf('CREATE DATABASE %s', $this->quoteIdent($dbName)));
        } catch (\PDOException $e) {
            throw new \RuntimeException("[db creation] Could not create database {$dbName}: " . $e->getMessage());
        }
        $this->console->ok("Temp DB created: {$dbName}");
    }

    /**
     * @param array{scheme: string, user: string, password: string, query: string} $credentials
     */
    private function safeDropDatabase(string $dbName, array $credentials): void
    {
        try {
            $pdo = $this->openPostgresConnection($credentials);
            $pdo->exec(sprintf('DROP DATABASE IF EXISTS %s', $this->quoteIdent($dbName)));
            $this->console->ok("Temp DB dropped: {$dbName}");
        } catch (\Throwable $e) {
            $this->console->warn("[final cleanup] Could not drop temp DB {$dbName}: " . $e->getMessage() . " — manual cleanup may be needed.");
        }
    }

    private function recordDatabaseInBacklog(string $dbName): void
    {
        $entryRef = $this->detectActiveEntryRef($this->agentCode);
        if ($entryRef === null) {
            $this->console->warn("Could not detect active entry-ref — skipping backlog meta record (non-fatal).");

            return;
        }

        $code = $this->app->runCommand(sprintf(
            'SOMANAGER_ROLE=developer SOMANAGER_AGENT=%s php scripts/backlog.php entry-set-meta %s %s',
            escapeshellarg($this->agentCode),
            escapeshellarg($entryRef),
            escapeshellarg('database=' . $dbName),
        ));
        if ($code !== 0) {
            $this->console->warn("Could not record temp DB in backlog metadata (non-fatal).");
        }
    }

    private function clearDatabaseFromBacklog(): void
    {
        $entryRef = $this->detectActiveEntryRef($this->agentCode);
        if ($entryRef === null) {
            $this->console->warn("Could not detect active entry-ref — skipping backlog meta clear (non-fatal).");

            return;
        }

        $code = $this->app->runCommand(sprintf(
            'SOMANAGER_ROLE=developer SOMANAGER_AGENT=%s php scripts/backlog.php entry-set-meta %s %s',
            escapeshellarg($this->agentCode),
            escapeshellarg($entryRef),
            escapeshellarg('database='),
        ));
        if ($code !== 0) {
            $this->console->warn("Could not clear temp DB from backlog metadata (non-fatal).");
        }
    }

    /**
     * Reads the backlog board and returns the entry-ref (feature or feature/task) for the
     * first in-progress entry assigned to the given agent.
     *
     * Returns null when the board file is missing or no matching entry is found.
     * The result is used as the explicit <entry-ref> argument to entry-set-meta.
     */
    private function detectActiveEntryRef(string $agentCode): ?string
    {
        $boardPath = $this->boardRoot . '/local/backlog/backlog-board.yaml';
        if (!is_file($boardPath)) {
            return null;
        }

        $boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        $board        = $boardService->loadBoard($boardPath);
        $matches      = $boardService->findActiveEntriesByAgent($board, $agentCode);

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
     * Handles the three common .env value formats:
     *   DATABASE_URL=postgresql://...        (unquoted)
     *   DATABASE_URL="postgresql://..."      (double-quoted)
     *   DATABASE_URL='postgresql://...'      (single-quoted)
     *
     * @return array{scheme: string, user: string, password: string, query: string}
     */
    private function parseDatabaseUrl(): array
    {
        $envFile = $this->projectRoot . '/.env';
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
     * Builds a DATABASE_URL pointing to localhost:5432 with the given temporary database name,
     * preserving the scheme and credentials extracted from the project's .env.
     *
     * @param array{scheme: string, user: string, password: string, query: string} $credentials
     */
    private function buildTempDatabaseUrl(string $dbName, array $credentials): string
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
     * Opens a PHP PDO connection to the postgres system database on localhost:5432.
     *
     * @param array{scheme: string, user: string, password: string, query: string} $credentials
     *
     * @throws \PDOException when the connection cannot be established
     */
    private function openPostgresConnection(array $credentials): \PDO
    {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', self::PG_HOST, self::PG_PORT);
        $pdo = new \PDO($dsn, $credentials['user'], $credentials['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        return $pdo;
    }

    /**
     * Runs a Symfony console command from the project's backend/ directory with the given DATABASE_URL.
     *
     * Executed locally without Docker; requires PHP and the configured database to be available
     * on the local system. If either is missing the command exits non-zero with an error from the
     * local toolchain.
     *
     * @param list<string> $extraArgs Additional CLI arguments appended after --no-interaction
     */
    private function runDoctrineCommand(string $subCommand, string $databaseUrl, array $extraArgs = []): int
    {
        $backendDir = $this->projectRoot . '/backend';

        $extra = implode(' ', array_map('escapeshellarg', $extraArgs));

        return $this->app->runCommand(sprintf(
            'cd %s && DATABASE_URL=%s php bin/console %s --no-interaction%s',
            escapeshellarg($backendDir),
            escapeshellarg($databaseUrl),
            escapeshellarg($subCommand),
            $extra !== '' ? ' ' . $extra : '',
        ));
    }

    /**
     * Quotes a PostgreSQL identifier with double quotes, escaping any embedded double quotes.
     */
    private function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
