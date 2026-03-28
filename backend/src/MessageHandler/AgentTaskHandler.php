<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\AgentTaskMessage;
use App\Repository\AgentRepository;
use App\Repository\TaskRepository;
use App\Service\AgentExecutionService;
use App\Service\LogService;
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
        private readonly LogService            $logService,
        private readonly LoggerInterface       $logger,
    ) {}

    public function __invoke(AgentTaskMessage $message): void
    {
        $task  = $this->taskRepository->find(Uuid::fromString($message->taskId));
        $agent = $this->agentRepository->find(Uuid::fromString($message->agentId));

        if ($task === null) {
            $this->logger->error('AgentTaskHandler: task not found', ['task_id' => $message->taskId]);
            $this->logService->record(
                source: 'worker',
                category: 'error',
                level: 'error',
                title: 'Agent task not found',
                // Stored in DB for the in-app log UI, so the human-facing message stays in French.
                message: sprintf('Tâche introuvable pour le worker: %s', $message->taskId),
                options: [
                    'task_id' => $message->taskId,
                    'request_ref' => $message->requestRef,
                    'trace_ref' => $message->traceRef,
                    'context' => ['skill_slug' => $message->skillSlug],
                ],
            );
            return;
        }

        if ($agent === null) {
            $this->logger->error('AgentTaskHandler: agent not found', ['agent_id' => $message->agentId]);
            $this->logService->record(
                source: 'worker',
                category: 'error',
                level: 'error',
                title: 'Agent runtime not found',
                // Stored in DB for the in-app log UI, so the human-facing message stays in French.
                message: sprintf('Agent introuvable pour le worker: %s', $message->agentId),
                options: [
                    'task_id' => $message->taskId,
                    'agent_id' => $message->agentId,
                    'request_ref' => $message->requestRef,
                    'trace_ref' => $message->traceRef,
                    'context' => ['skill_slug' => $message->skillSlug],
                ],
            );
            return;
        }

        try {
            $this->logService->record(
                source: 'worker',
                category: 'runtime',
                level: 'info',
                title: 'Agent execution started',
                // Stored in DB for the in-app log UI, so the human-facing message stays in French.
                message: sprintf('Exécution de %s par %s', $task->getTitle(), $agent->getName()),
                options: [
                    'project_id' => (string) $task->getProject()->getId(),
                    'task_id' => (string) $task->getId(),
                    'agent_id' => (string) $agent->getId(),
                    'request_ref' => $message->requestRef,
                    'trace_ref' => $message->traceRef,
                    'context' => ['skill_slug' => $message->skillSlug],
                ],
            );

            $this->executionService->execute($task, $agent, $message->skillSlug);
        } catch (\Throwable $e) {
            $this->taskService->failExecution($task, $e->getMessage());
            $this->logService->recordError('worker', 'Agent execution failed', $e, [
                'project_id' => (string) $task->getProject()->getId(),
                'task_id' => (string) $task->getId(),
                'agent_id' => (string) $agent->getId(),
                'request_ref' => $message->requestRef,
                'trace_ref' => $message->traceRef,
                'context' => [
                    'task_title' => $task->getTitle(),
                    'agent_name' => $agent->getName(),
                    'skill_slug' => $message->skillSlug,
                ],
            ]);

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
