<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message dispatché quand une tâche doit être exécutée par un agent.
 * Traité de façon asynchrone par AgentTaskHandler via Redis.
 */
final class AgentTaskMessage
{
    public function __construct(
        public readonly string $taskId,
        public readonly string $agentId,
        public readonly string $skillSlug,
        public readonly ?string $requestRef = null,
        public readonly ?string $traceRef = null,
    ) {}
}
