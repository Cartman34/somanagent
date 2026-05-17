<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Enum;

/**
 * AI coding client that can be launched by backlog-agent.php.
 */
enum AgentClient: string
{
    case CLAUDE = 'claude';
    case CODEX = 'codex';
    case OPENCODE = 'opencode';
    case GEMINI = 'gemini';


}
