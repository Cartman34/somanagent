<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Command;

/**
 * Tombstone for the removed resume command.
 *
 * The resume command has been merged into start: start --code=<code> now handles
 * attach (live session), ghost-cleanup (dead session), and new-session creation.
 */
final class AgentResumeCommand extends AbstractAgentCommand
{
    /**
     * {@inheritdoc}
     */
    public function handle(array $args, array $options): int
    {
        throw new \RuntimeException(
            "The 'resume' command has been removed. Use 'start --code=<code>' instead — " .
            'it handles attach and recovery automatically.',
        );
    }
}
