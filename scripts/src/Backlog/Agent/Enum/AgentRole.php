<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Enum;

/**
 * Role of an agent session launched by backlog-agent.php.
 */
enum AgentRole: string
{
    case DEVELOPER = 'developer';
    case REVIEWER = 'reviewer';
    case MANAGER = 'manager';

    /**
     * Returns the one-letter prefix used in agent codes.
     */
    public function codePrefix(): string
    {
        return match ($this) {
            self::DEVELOPER => 'd',
            self::REVIEWER => 'r',
            self::MANAGER => 'm',
        };
    }

    /**
     * Returns the human-readable label for the role.
     */
    public function label(): string
    {
        return $this->value;
    }
}
