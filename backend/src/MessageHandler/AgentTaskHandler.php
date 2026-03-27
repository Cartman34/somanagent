<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\AgentTaskMessage;
use App\Repository\AgentRepository;
use App\Repository\TaskRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Consomme les AgentTaskMessage depuis Redis et exécute la tâche agent.
 * Lancé par : php bin/console messenger:consume async
 */
#[AsMessageHandler]
final class AgentTaskHandler
{
    public function __construct(
        private readonly AgentRepository  $agentRepository,
        private readonly TaskRepository   $taskRepository,
        private readonly LoggerInterface  $logger,
    ) {}

    public function __invoke(AgentTaskMessage $message): void
    {
        $task  = $this->taskRepository->find(Uuid::fromString($message->taskId));
        $agent = $this->agentRepository->find(Uuid::fromString($message->agentId));

        if ($task === null || $agent === null) {
            $this->logger->error('AgentTaskHandler: task or agent not found', [
                'task_id'  => $message->taskId,
                'agent_id' => $message->agentId,
            ]);
            return;
        }

        $this->logger->info('AgentTaskHandler: starting task execution', [
            'task'       => $task->getTitle(),
            'agent'      => $agent->getName(),
            'skill_slug' => $message->skillSlug,
        ]);

        // TODO Phase 3 (execution) : appel AgentPort + parse JSON structuré + mise à jour tâches
    }
}
