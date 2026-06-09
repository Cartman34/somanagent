#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 *
 * Purpose:      Rename the `agent` key to `developer` in both `todo` and `active` sections
 *               of local/backlog/backlog-board.yaml.
 * Introduced:   2026-05-19
 * Remove after: All known WPs have been migrated and no operator runs a backlog.php version
 *               that still writes the `agent` key. Tracked in scripts/backlog/doc/operating/migrations.md.
 *
 * Behaviour:
 * - Reads local/backlog/backlog-board.yaml from the project root.
 * - Rewrites every `agent:` key to `developer:` in the `todo` and `active` sections.
 * - Idempotent: if no `agent:` key is found, logs and exits 0 without rewriting.
 * - Writes the result back in place (atomic via a temp file + rename where possible).
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$boardPath = $projectRoot . '/local/backlog/backlog-board.yaml';
$markerPath = $projectRoot . '/local/backlog/migrations.applied';
$migrationName = basename(__FILE__);

echo "Migration 2026-05-19-rename-agent-to-developer: renaming agent → developer in board YAML\n";

if (!is_file($boardPath)) {
    echo "Skip (board not found): {$boardPath}\n";
} else {
    $content = file_get_contents($boardPath);
    if ($content === false) {
        fwrite(STDERR, "ERROR: failed to read {$boardPath}\n");
        exit(1);
    }

    if (!str_contains($content, "\n  agent: ") && !str_contains($content, "\n    agent: ")) {
        echo "Skip (no `agent:` key found — already migrated or empty board).\n";
    } else {
        $updated = preg_replace('/^(\s+)agent:/m', '$1developer:', $content);
        if ($updated === null) {
            fwrite(STDERR, "ERROR: preg_replace failed\n");
            exit(1);
        }

        $tmp = $boardPath . '.tmp';
        if (file_put_contents($tmp, $updated) === false) {
            fwrite(STDERR, "ERROR: failed to write temp file {$tmp}\n");
            exit(1);
        }
        if (!rename($tmp, $boardPath)) {
            fwrite(STDERR, "ERROR: failed to replace {$boardPath}\n");
            exit(1);
        }

        echo "Updated: renamed agent → developer in {$boardPath}\n";
    }
}

$applied = is_file($markerPath) ? file($markerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
if ($applied === false) {
    fwrite(STDERR, "ERROR: failed to read {$markerPath}\n");
    exit(1);
}
if (!in_array($migrationName, $applied, true)) {
    $applied[] = $migrationName;
    sort($applied);
    if (file_put_contents($markerPath, implode("\n", $applied) . "\n") === false) {
        fwrite(STDERR, "ERROR: failed to update {$markerPath}\n");
        exit(1);
    }
    echo "Marked applied: {$migrationName}\n";
}

echo "Done.\n";
