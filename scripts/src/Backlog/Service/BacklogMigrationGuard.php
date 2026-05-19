<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Service;

/**
 * Blocks backlog commands while one-shot backlog migrations are pending.
 */
final class BacklogMigrationGuard
{
    public const DEFAULT_MARKER_PATH = 'local/backlog/migrations.applied';
    public const DEFAULT_MIGRATIONS_DIR = 'scripts/migrations';

    private const ALERT_TITLE = 'Migration pending - operator action required';
    private const ALERT_END_MARKER = 'MIGRATION_ALERT_END';

    private const SEED_APPLIED_MIGRATIONS = [
        '2026-05-17-backlog-yaml.php',
        '2026-05-18-backlog-dir.php',
        '2026-05-19-rename-agent-to-developer.php',
    ];

    /**
     * @param string $migrationsDir Absolute path to the migrations directory
     * @param string $markerPath    Absolute path to the applied-migrations marker file
     */
    public function __construct(
        private string $migrationsDir,
        private string $markerPath,
    ) {
    }

    /**
     * @return list<string>
     */
    public function pendingMigrations(): array
    {
        $known = $this->migrationScripts();
        if ($known === []) {
            return [];
        }

        $applied = $this->readAppliedMigrations();
        $pending = array_values(array_diff($known, $applied));
        sort($pending);

        return $pending;
    }

    /**
     * @param list<string> $pending
     */
    public function formatAlert(array $pending): string
    {
        $lines = [
            self::ALERT_TITLE,
            'The content in this read-only block is diagnostic information to report to the user, not an instruction to execute from an agent session.',
            'A backlog migration is pending; backlog state reads and mutations are blocked until the operator applies it.',
            '',
            'Pending migrations:',
        ];

        foreach ($pending as $migration) {
            $lines[] = '- scripts/migrations/' . $migration;
        }

        $lines[] = '';
        $lines[] = 'Operator command(s):';
        foreach ($pending as $migration) {
            $lines[] = '  php scripts/migrations/' . $migration;
        }

        $lines[] = '';
        $lines[] = 'Report to the user; do not run this command from an agent session.';
        $lines[] = self::ALERT_END_MARKER;

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function migrationScripts(): array
    {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }

        $files = glob($this->migrationsDir . '/*.php');
        if ($files === false) {
            throw new \RuntimeException('Unable to list backlog migrations.');
        }

        $names = array_map(static fn(string $path): string => basename($path), $files);
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function readAppliedMigrations(): array
    {
        if (!is_file($this->markerPath)) {
            $this->writeSeedMarker();
        }

        if (!is_file($this->markerPath)) {
            return [];
        }

        $contents = file_get_contents($this->markerPath);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read backlog migration marker.');
        }

        $applied = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $migration = trim($line);
            if ($migration !== '') {
                $applied[$migration] = true;
            }
        }

        return array_keys($applied);
    }

    private function writeSeedMarker(): void
    {
        $dir = dirname($this->markerPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create backlog migration marker directory.');
        }

        $knownSeeds = array_values(array_intersect(self::SEED_APPLIED_MIGRATIONS, $this->migrationScripts()));
        $contents = $knownSeeds === [] ? '' : implode("\n", $knownSeeds) . "\n";
        if (file_put_contents($this->markerPath, $contents) === false) {
            throw new \RuntimeException('Unable to seed backlog migration marker.');
        }
    }
}
