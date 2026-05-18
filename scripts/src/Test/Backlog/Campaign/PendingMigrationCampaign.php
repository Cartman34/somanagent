<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Test\Backlog\Campaign;

use SoManAgent\Script\Test\Backlog\BacklogScriptTestContext;
use SoManAgent\Script\Test\Backlog\BacklogScriptTestDriver;

/**
 * Pending migration campaign.
 */
final class PendingMigrationCampaign implements CampaignInterface
{
    private const APPLIED_MIGRATION = '2026-05-18-backlog-dir.php';
    private const PENDING_MIGRATION = '2026-05-19-future-backlog-change.php';

    public function getName(): string
    {
        return 'pending-migration';
    }

    public function run(BacklogScriptTestDriver $driver, BacklogScriptTestContext $context): void
    {
        $driver->runBacklog(['list']);

        $this->writeMigration($context, self::APPLIED_MIGRATION);
        $driver->runBacklog(['list']);

        $this->writeMigration($context, self::PENDING_MIGRATION);
        $driver->assertBacklogFails(['list'], 'Migration pending - operator action required');
        $driver->assertBacklogFails(['status'], self::PENDING_MIGRATION);
        $driver->assertBacklogFails(['todo-list'], 'MIGRATION_ALERT_END');

        $this->markApplied($context, self::PENDING_MIGRATION);
        $driver->runBacklog(['todo-list']);
    }

    private function writeMigration(BacklogScriptTestContext $context, string $name): void
    {
        if (!is_dir($context->migrationsDir) && !mkdir($context->migrationsDir, 0777, true) && !is_dir($context->migrationsDir)) {
            throw new \RuntimeException('Unable to create test migrations directory.');
        }

        $path = $context->migrationsDir . '/' . $name;
        if (file_put_contents($path, "<?php\n") === false) {
            throw new \RuntimeException("Unable to write test migration: {$path}");
        }
    }

    private function markApplied(BacklogScriptTestContext $context, string $name): void
    {
        $current = is_file($context->migrationMarkerPath)
            ? file($context->migrationMarkerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            : [];
        if ($current === false) {
            throw new \RuntimeException('Unable to read test migration marker.');
        }
        if (!in_array($name, $current, true)) {
            $current[] = $name;
        }
        sort($current);

        if (file_put_contents($context->migrationMarkerPath, implode("\n", $current) . "\n") === false) {
            throw new \RuntimeException('Unable to write test migration marker.');
        }
    }
}
