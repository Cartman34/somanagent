<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Runner;

use Sowapps\SoManAgent\Script\Service\GenerateMigrationService;
use Sowapps\Toolkit\Runner\AbstractScriptRunner;

/**
 * Generate migration script runner (controller).
 *
 * Produces an isolated Doctrine diff against a temporary database so that the shared application
 * database is never used as the diff target. This runner owns the orchestration and the display;
 * the injected {@see GenerateMigrationService} is a silent thin model performing the DB/board
 * operations and returning data.
 *
 * Steps (each step name appears in the error when it fails):
 *   1. agent detection   — resolve the agent code from the environment
 *   2. prerequisites     — verify PHP can connect to PostgreSQL on localhost:5432
 *   3. lock              — acquire the per-agent advisory lock
 *   4. initial cleanup   — drop any leftover temporary database from a prior run
 *   5. db creation       — create the temporary database
 *   6. backlog meta      — record the temp DB name on the active backlog entry (non-fatal)
 *   7. migrations        — apply existing migrations on the temporary database
 *   8. doctrine diff     — run doctrine:migrations:diff against the temporary database
 *   9. final cleanup     — drop the temporary database, clear backlog meta, and release the lock
 */
final class GenerateMigrationRunner extends AbstractScriptRunner
{
    private const NAME = 'generate-migration';

    public function __construct(
        private readonly GenerateMigrationService $service,
    ) {
        parent::__construct();
    }

    protected function getName(): string
    {
        return self::NAME;
    }

    protected function getDescription(): string
    {
        return 'Generate a Doctrine migration using an isolated temporary database';
    }

    protected function getOptions(): array
    {
        return [];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/generate-migration.php',
        ];
    }

    /**
     * Generates a Doctrine migration diff using a temporary database.
     *
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        [$positional, $options] = $this->parseArgs($args);

        if ($positional !== []) {
            $this->console->fail('generate-migration does not accept arguments.');
        }

        if ($options !== []) {
            $this->console->fail('generate-migration does not accept options.');
        }

        try {
            $agentCode = $this->service->resolveAgentCode();
        } catch (\RuntimeException $e) {
            $this->console->fail($e->getMessage());
        }

        $dbName   = GenerateMigrationService::buildTempDbName($agentCode);
        $lockPath = $this->service->buildLockPath($agentCode);

        $this->console->info("Agent: {$agentCode}");
        $this->console->info("Temp DB: {$dbName}");

        $credentials = $this->service->loadDatabaseCredentials();

        // ── Step: prerequisites ───────────────────────────────────────────────
        $this->console->step('Checking prerequisites — PHP connection to local PostgreSQL');
        try {
            $this->service->assertLocalPrerequisites($credentials);
        } catch (\PDOException $e) {
            $this->console->fail(implode("\n", [
                '[prerequisites] Cannot connect to PostgreSQL on ' . $this->service->getDbEndpoint() . '.',
                '  PHP DSN: ' . $this->service->getSystemDsn(),
                '  Working directory: ' . $this->service->getProjectRoot(),
                '  Cause: ' . $e->getMessage(),
                '  Action: ensure the Docker PostgreSQL service is running and accessible on localhost:5432 (e.g. run docker compose up -d db from Main Worktree).',
            ]));
        }
        $this->console->ok('PHP connected to local PostgreSQL successfully.');

        // ── Step: lock ────────────────────────────────────────────────────────
        $this->console->step('Acquiring per-agent lock');
        $lockHandle = $this->service->acquireLock($lockPath);
        $this->console->ok('Lock acquired.');

        try {
            // ── Step: initial cleanup ─────────────────────────────────────────
            $this->console->step('Initial cleanup — dropping leftover temp DB');
            $this->service->dropDatabase($dbName, $credentials);
            $this->console->ok('Old temp DB removed (if existed).');

            // ── Step: db creation ─────────────────────────────────────────────
            $this->console->step('Creating temp DB');
            $this->service->createDatabase($dbName, $credentials);
            $this->console->ok("Temp DB created: {$dbName}");

            try {
                // ── Step: record in backlog meta ──────────────────────────────
                if (!$this->service->recordDatabaseInBacklog($agentCode, $dbName)) {
                    $this->console->warn('Could not record temp DB in backlog metadata (non-fatal).');
                }

                $databaseUrl = $this->service->buildTempDatabaseUrl($dbName, $credentials);
                $this->console->info('DB host: ' . $this->service->getDbEndpoint());

                // ── Step: migrations ──────────────────────────────────────────
                $this->console->step('Applying existing migrations on temp DB');
                $code = $this->service->runDoctrineCommand('doctrine:migrations:migrate', $databaseUrl);
                if ($code !== 0) {
                    throw new \RuntimeException("[migrations] doctrine:migrations:migrate failed (exit {$code}).");
                }

                // ── Step: doctrine diff ───────────────────────────────────────
                $this->console->step('Generating migration diff');
                $code = $this->service->runDoctrineCommand('doctrine:migrations:diff', $databaseUrl, ['--allow-empty-diff']);
                if ($code !== 0) {
                    throw new \RuntimeException("[doctrine diff] doctrine:migrations:diff failed (exit {$code}).");
                }
            } finally {
                // ── Step: final cleanup ───────────────────────────────────────
                $this->console->step('Final cleanup — dropping temp DB');
                $warning = $this->service->safeDropDatabase($dbName, $credentials);
                if ($warning !== null) {
                    $this->console->warn($warning);
                } else {
                    $this->console->ok("Temp DB dropped: {$dbName}");
                }
                try {
                    if (!$this->service->clearDatabaseFromBacklog($agentCode)) {
                        $this->console->warn('Could not clear temp DB from backlog metadata (non-fatal).');
                    }
                } catch (\Throwable $e) {
                    $this->console->warn('[final cleanup] Error while clearing backlog meta: ' . $e->getMessage());
                }
            }
        } finally {
            $this->service->releaseLock($lockHandle);
        }

        $this->console->ok('Migration diff generated successfully.');

        return 0;
    }
}
