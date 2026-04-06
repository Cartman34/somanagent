<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Type of connector used by an agent to communicate with Claude.
 */
enum ConnectorType: string
{
    case ClaudeApi = 'claude_api';
    case ClaudeCli = 'claude_cli';

    /**
     * Returns a human-readable label for the connector type.
     */
    public function label(): string
    {
        return match($this) {
            self::ClaudeApi => 'Claude API',
            self::ClaudeCli => 'Claude CLI',
        };
    }
}
