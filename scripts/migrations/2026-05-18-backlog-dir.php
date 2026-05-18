#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 *
 * Purpose:      Move backlog files from local/ to local/backlog/ and update the lock path.
 *               local/backlog-board.yaml → local/backlog/backlog-board.yaml
 *               local/backlog-review.md  → local/backlog/backlog-review.md
 *               local/tmp/backlog.lock   → local/backlog/backlog.lock (lock is transient; not moved)
 * Introduced:   2026-05-18
 * Remove after: All WPs have been migrated and no operator is running a backlog.php version
 *               that still expects the old paths. Tracked in doc/development/migrations.md.
 *
 * Behaviour:
 * - Creates local/backlog/ if absent.
 * - Moves local/backlog-board.yaml → local/backlog/backlog-board.yaml when the source exists
 *   and the destination does not (idempotent).
 * - Moves local/backlog-review.md → local/backlog/backlog-review.md under the same condition.
 * - The lock file (local/tmp/backlog.lock) is transient; it is not migrated. It will be
 *   created at the new path (local/backlog/backlog.lock) on the next mutating backlog command.
 * - Reports every action taken to stdout. Exits 0 on success, 1 on any filesystem error.
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);

$backlogDir = $projectRoot . '/local/backlog';
$oldBoard = $projectRoot . '/local/backlog-board.yaml';
$newBoard = $backlogDir . '/backlog-board.yaml';
$oldReview = $projectRoot . '/local/backlog-review.md';
$newReview = $backlogDir . '/backlog-review.md';

echo "Migration 2026-05-18-backlog-dir: moving backlog files to local/backlog/\n";

if (!is_dir($backlogDir)) {
    if (!mkdir($backlogDir, 0755, true)) {
        fwrite(STDERR, "ERROR: failed to create {$backlogDir}\n");
        exit(1);
    }
    echo "Created: local/backlog/\n";
}

$moveFile = static function (string $src, string $dst): void {
    if (!is_file($src)) {
        echo "Skip (source absent): " . basename($src) . "\n";
        return;
    }
    if (is_file($dst)) {
        echo "Skip (destination exists): " . basename($dst) . "\n";
        return;
    }
    if (!rename($src, $dst)) {
        fwrite(STDERR, "ERROR: failed to move {$src} → {$dst}\n");
        exit(1);
    }
    echo "Moved: " . basename($src) . " → local/backlog/" . basename($dst) . "\n";
};

$moveFile($oldBoard, $newBoard);
$moveFile($oldReview, $newReview);

echo "Done.\n";
