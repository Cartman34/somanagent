<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Setup;

use Sowapps\Toolkit\Console;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogConfig;

/**
 * Materializes local config files from their committed .dist templates.
 *
 * Called during `setup.php install` and `setup.php update`. Never overwrites an
 * existing local config. In dry-run mode, prints what would be copied without
 * touching the filesystem.
 */
final class LocalConfigBootstrap
{
    private const LOCAL_BACKLOG_DIR = 'local/backlog';

    /**
     * Copies the backlog config .dist to local/backlog/config.yaml if absent.
     *
     * Idempotent: no-op when the local file already exists.
     * Atomic: writes to a temp file then renames to prevent partial writes.
     *
     * @throws \RuntimeException When the .dist template is missing or the copy fails.
     */
    public static function materialize(string $root, bool $dryRun, Console $console): void
    {
        $distPath = $root . '/' . BacklogConfig::DIST_PATH;
        $localPath = $root . '/' . BacklogConfig::LOCAL_PATH;
        $localDir = $root . '/' . self::LOCAL_BACKLOG_DIR;

        if (!is_file($distPath)) {
            throw new \RuntimeException(
                sprintf(
                    "Backlog config template not found: '%s'. The repository may be corrupted.",
                    BacklogConfig::DIST_PATH,
                ),
            );
        }

        if ($dryRun) {
            if (!is_file($localPath)) {
                $console->line(sprintf(
                    '  [dry-run] Would copy %s → %s',
                    BacklogConfig::DIST_PATH,
                    BacklogConfig::LOCAL_PATH,
                ));
            } else {
                $console->line(sprintf(
                    '  [dry-run] %s: already present, skipping.',
                    BacklogConfig::LOCAL_PATH,
                ));
            }

            return;
        }

        if (is_file($localPath)) {
            $console->info(sprintf('%s: already present, skipping.', BacklogConfig::LOCAL_PATH));

            return;
        }

        if (!is_dir($localDir) && !mkdir($localDir, 0o755, true) && !is_dir($localDir)) {
            throw new \RuntimeException(sprintf("Failed to create directory '%s'.", self::LOCAL_BACKLOG_DIR));
        }

        $tmpPath = $localPath . '.tmp.' . uniqid('', true);
        if (!copy($distPath, $tmpPath)) {
            throw new \RuntimeException(
                sprintf("Failed to copy '%s' to a temporary file.", BacklogConfig::DIST_PATH),
            );
        }
        if (!rename($tmpPath, $localPath)) {
            @unlink($tmpPath);
            throw new \RuntimeException(
                sprintf("Failed to materialize '%s' (rename failed).", BacklogConfig::LOCAL_PATH),
            );
        }

        $console->ok(sprintf('%s: Created.', BacklogConfig::LOCAL_PATH));
    }
}
