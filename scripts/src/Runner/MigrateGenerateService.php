<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\Application;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\TextSlugger;

/**
 * Runs doctrine:migrations:diff against a temporary isolated database.
 *
 * The temporary database is named {agentCode}_migrate_gen so that concurrent
 * agents never share the same target. A per-agent file lock prevents the same
 * agent from running two concurrent generate operations.
 *
 * Steps (each step name appears in the error when it fails):
 *   1. agent detection   — resolve the agent code from the WA path or env
 *   2. lock              — acquire the per-agent advisory lock
 *   3. initial cleanup   — drop any leftover temporary database from a prior run
 *   4. db creation       — create the temporary database
 *   5. backlog meta      — record the temp DB name on the active backlog entry (non-fatal)
 *   6. migrations        — apply existing migrations on the temporary database
 *   7. doctrine diff     — run doctrine:migrations:diff against the temporary database
 *   8. final cleanup     — drop the temporary database, clear backlog meta, and release the lock
 */
final class MigrateGenerateService
{
    private const LOCK_TIMEOUT_SECONDS = 30;

    private DockerComposeServiceRunner $phpRunner;
    private DockerComposeServiceRunner $dbRunner;
    private readonly Console $console;

    /**
     * @param Application $app
     * @param string $agentCode Resolved agent code (e.g. "d04")
     * @param string $projectRoot Absolute path to the project root (WA or WP), used for lock files and DATABASE_URL
     * @param string $boardRoot Absolute path to the WP root where the canonical backlog board lives
     */
    public function __construct(
        private readonly Application $app,
        private readonly string $agentCode,
        private readonly string $projectRoot,
        private readonly string $boardRoot,
    ) {
        $this->phpRunner = new DockerComposeServiceRunner($app, 'php');
        $this->dbRunner  = new DockerComposeServiceRunner($app, 'db');
        $this->console   = $app->console;
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

        // ── Step: lock ────────────────────────────────────────────────────────
        $this->console->step('Acquiring per-agent lock');
        $lockHandle = $this->acquireLock($lockPath);

        try {
            // ── Step: initial cleanup ─────────────────────────────────────────
            $this->console->step('Initial cleanup — dropping leftover temp DB');
            $this->dropDatabase($dbName);

            // ── Step: db creation ─────────────────────────────────────────────
            $this->console->step('Creating temp DB');
            $this->createDatabase($dbName);

            // ── Step: record in backlog meta ──────────────────────────────────
            $this->recordDatabaseInBacklog($dbName);

            try {
                $databaseUrl = $this->buildTempDatabaseUrl($dbName);

                // ── Step: migrations ──────────────────────────────────────────
                $this->console->step('Applying existing migrations on temp DB');
                $code = $this->phpRunner->run(
                    ['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'],
                    false,
                    ['DATABASE_URL' => $databaseUrl],
                );
                if ($code !== 0) {
                    throw new \RuntimeException("[migrations] doctrine:migrations:migrate failed (exit {$code}).");
                }

                // ── Step: doctrine diff ───────────────────────────────────────
                $this->console->step('Generating migration diff');
                $code = $this->phpRunner->run(
                    ['php', 'bin/console', 'doctrine:migrations:diff', '--no-interaction'],
                    false,
                    ['DATABASE_URL' => $databaseUrl],
                );
                if ($code !== 0) {
                    throw new \RuntimeException("[doctrine diff] doctrine:migrations:diff failed (exit {$code}).");
                }
            } finally {
                // ── Step: final cleanup ───────────────────────────────────────
                $this->console->step('Final cleanup — dropping temp DB');
                $this->safeDropDatabase($dbName);
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

    private function dropDatabase(string $dbName): void
    {
        $sql = sprintf('DROP DATABASE IF EXISTS %s', $this->quoteIdent($dbName));
        $code = $this->dbRunner->run(['psql', '-U', 'somanagent', '-d', 'postgres', '-c', $sql]);
        if ($code !== 0) {
            throw new \RuntimeException("[initial cleanup] Could not drop database {$dbName} (exit {$code}).");
        }
        $this->console->ok("Old temp DB removed (if existed).");
    }

    private function createDatabase(string $dbName): void
    {
        $sql = sprintf('CREATE DATABASE %s', $this->quoteIdent($dbName));
        $code = $this->dbRunner->run(['psql', '-U', 'somanagent', '-d', 'postgres', '-c', $sql]);
        if ($code !== 0) {
            throw new \RuntimeException("[db creation] Could not create database {$dbName} (exit {$code}).");
        }
        $this->console->ok("Temp DB created: {$dbName}");
    }

    private function safeDropDatabase(string $dbName): void
    {
        try {
            $sql  = sprintf('DROP DATABASE IF EXISTS %s', $this->quoteIdent($dbName));
            $code = $this->dbRunner->run(['psql', '-U', 'somanagent', '-d', 'postgres', '-c', $sql]);
            if ($code !== 0) {
                $this->console->warn("[final cleanup] Could not drop temp DB {$dbName} (exit {$code}) — manual cleanup may be needed.");
            } else {
                $this->console->ok("Temp DB dropped: {$dbName}");
            }
        } catch (\Throwable $e) {
            $this->console->warn("[final cleanup] Error while dropping temp DB: " . $e->getMessage());
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
        $boardPath = $this->boardRoot . '/local/backlog-board.md';
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
     * Reads DATABASE_URL from the project root .env and replaces the database name
     * with the given temporary database name.
     *
     * Handles the three common .env value formats:
     *   DATABASE_URL=postgresql://...        (unquoted)
     *   DATABASE_URL="postgresql://..."      (double-quoted)
     *   DATABASE_URL='postgresql://...'      (single-quoted)
     */
    private function buildTempDatabaseUrl(string $dbName): string
    {
        $envFile = $this->projectRoot . '/.env';
        $content = is_file($envFile) ? file_get_contents($envFile) : false;

        if ($content === false) {
            throw new \RuntimeException("[migrations] Cannot read .env file: {$envFile}");
        }

        if (preg_match('/^DATABASE_URL=("([^"]+)"|\'([^\']+)\'|([^\s]+))\s*$/m', $content, $matches) !== 1) {
            throw new \RuntimeException("[migrations] DATABASE_URL not found in .env");
        }

        $url = ($matches[2] ?? '') !== '' ? $matches[2] : (($matches[3] ?? '') !== '' ? $matches[3] : ($matches[4] ?? ''));
        $replaced = preg_replace('#/([^/?]+)(\?|$)#', '/' . $dbName . '$2', $url, 1);

        if (!is_string($replaced) || $replaced === $url) {
            throw new \RuntimeException("[migrations] Could not substitute database name in DATABASE_URL.");
        }

        return $replaced;
    }

    /**
     * Quotes a PostgreSQL identifier with double quotes, escaping any embedded double quotes.
     */
    private function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
