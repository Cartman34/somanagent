<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

enum ConnectorType: string
{
    case ClaudeApi = 'claude_api';
    case ClaudeCli = 'claude_cli';

    public function label(): string
    {
        return match($this) {
            self::ClaudeApi => 'Claude API',
            self::ClaudeCli => 'Claude CLI',
        };
    }
}
