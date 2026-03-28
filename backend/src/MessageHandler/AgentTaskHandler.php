<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\AgentTaskMessage;
use App\Repository\AgentRepository;
use App\Repository\TaskRepository;
use App\Service\AgentExecutionService;
use App\Service\TaskService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Consomme les AgentTaskMessage depuis Redis et délègue l'exécution à AgentExecutionService.
 * Lancé par : php bin/console messenger:consume async
 */
#[AsMessageHandler]
final class AgentTaskHandler
{
    public function __construct(
        private readonly AgentRepository       $agentRepository,
        private readonly TaskRepository        $taskRepository,
        private readonly AgentExecutionService $executionService,
        private readonly TaskService           $taskService,
        private readonly LoggerInterface       $logger,
    ) {}

    public function __invoke(AgentTaskMessage $message): void
    {
        $task  = $this->taskRepository->find(Uuid::fromString($message->taskId));
        $agent = $this->agentRepository->find(Uuid::fromString($message->agentId));

        if ($task === null) {
            $this->logger->error('AgentTaskHandler: task not found', ['task_id' => $message->taskId]);
            return;
        }

        if ($agent === null) {
            $this->logger->error('AgentTaskHandler: agent not found', ['agent_id' => $message->agentId]);
            return;
        }

        try {
            $this->executionService->execute($task, $agent, $message->skillSlug);
        } catch (\Throwable $e) {
            $this->taskService->failExecution($task, $e->getMessage());

            $this->logger->error('AgentTaskHandler: execution failed', [
                'task'      => $task->getTitle(),
                'agent'     => $agent->getName(),
                'skill'     => $message->skillSlug,
                'error'     => $e->getMessage(),
                'exception' => $e::class,
            ]);

            // Re-throw so Messenger can route to the failed transport
            throw $e;
        }
    }
}
