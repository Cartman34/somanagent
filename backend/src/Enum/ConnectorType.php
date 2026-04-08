<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Type of connector used by an agent runtime.
 */
enum ConnectorType: string
{
    case ClaudeApi = 'claude_api';
    case ClaudeCli = 'claude_cli';
    case CodexApi = 'codex_api';
    case CodexCli = 'codex_cli';
    case OpenCodeCli = 'opencode_cli';

    /**
     * Returns a human-readable label for the connector type.
     */
    public function label(): string
    {
        return match($this) {
            self::ClaudeApi => 'Claude API',
            self::ClaudeCli => 'Claude CLI',
            self::CodexApi => 'Codex API',
            self::CodexCli => 'Codex CLI',
            self::OpenCodeCli => 'OpenCode CLI',
        };
    }
}
