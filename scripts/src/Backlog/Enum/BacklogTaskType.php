<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Enum;

/**
 * Canonical task types recognized as queued task prefixes and as Git branch prefixes.
 *
 * Each case value is both the textual prefix (e.g. `feat` in `[feat]`) and the Git
 * branch prefix (e.g. `feat/<slug>`). Adding a type here automatically extends
 * task-create, work-start and the related documentation.
 */
enum BacklogTaskType: string
{
    case FEAT = 'feat';
    case FIX = 'fix';
    case TECH = 'tech';

    /**
     * Returns the case matching a textual token (case-insensitive), or null when the token
     * does not name any known task type.
     */
    public static function tryFromToken(string $token): ?self
    {
        $normalized = strtolower(trim($token));
        if ($normalized === '') {
            return null;
        }

        foreach (self::cases() as $case) {
            if ($case->value === $normalized) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Returns the comma-separated list of known type tokens, useful for error messages.
     */
    public static function tokenList(): string
    {
        return implode(', ', array_map(static fn(self $case): string => $case->value, self::cases()));
    }

    /**
     * Returns the Git branch prefix for this type (without the trailing slash).
     */
    public function branchPrefix(): string
    {
        return $this->value;
    }
}
