<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

/**
 * Single source of truth for all backlog file and directory paths, relative to the project root.
 *
 * Use the static helpers to build absolute paths. Never concatenate these literals manually.
 */
final class BacklogPaths
{
    public const BOARD = 'local/backlog/backlog-board.yaml';

    public const REVIEW = 'local/backlog/backlog-review.md';

    public const REVIEW_RESULT = 'local/backlog-review-result.txt';

    public const DIRECTORY = 'local/backlog';

    /**
     * Returns the absolute path to the backlog board file.
     */
    public static function boardPath(string $root): string
    {
        return $root . '/' . self::BOARD;
    }

    /**
     * Returns the absolute path to the backlog review file.
     */
    public static function reviewPath(string $root): string
    {
        return $root . '/' . self::REVIEW;
    }

    /**
     * Returns the absolute path to the review result file (relative to a worktree or project root).
     */
    public static function reviewResultPath(string $root): string
    {
        return $root . '/' . self::REVIEW_RESULT;
    }

    /**
     * Returns the absolute path to the backlog directory.
     */
    public static function directory(string $root): string
    {
        return $root . '/' . self::DIRECTORY;
    }
}
