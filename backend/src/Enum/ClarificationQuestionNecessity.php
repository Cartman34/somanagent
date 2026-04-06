<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Qualifies how necessary a clarification question is for the current task execution.
 */
enum ClarificationQuestionNecessity: string
{
    case Blocking = 'blocking';
    case Important = 'important';
    case Useful = 'useful';

    /**
     * Resolves one necessity level from untrusted log metadata.
     */
    public static function tryFromMetadata(?array $metadata): ?self
    {
        $value = $metadata['necessityLevel'] ?? null;
        if (!is_string($value)) {
            return null;
        }

        return self::tryFrom(mb_strtolower(trim($value)));
    }

    /**
     * Indicates whether the question blocks completion until answered.
     */
    public function isBlocking(): bool
    {
        return $this === self::Blocking;
    }
}
