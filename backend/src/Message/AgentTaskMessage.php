<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Message;

/**
 * Message dispatched when a task must be executed by an agent.
 * Processed asynchronously by AgentTaskHandler via Redis.
 */
final class AgentTaskMessage
{
    /**
     * Carries all identifiers needed by AgentTaskHandler to locate and execute the task.
     */
    public function __construct(
        public readonly string $ticketTaskId,
        public readonly string $agentId,
        public readonly string $skillSlug,
        public readonly string $agentTaskExecutionId,
        public readonly ?string $requestRef = null,
        public readonly ?string $traceRef = null,
    ) {}
}
