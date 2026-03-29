<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\AgentTaskMessage;
use App\Repository\AgentRepository;
use App\Repository\TaskRepository;
use App\Service\AgentExecutionService;
use App\Service\LogService;
use App\Service\MessengerExecutionContext;
use App\Service\TaskExecutionService;
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
        private readonly TaskExecutionService  $taskExecutionService,
        private readonly LogService            $logService,
        private readonly MessengerExecutionContext $messengerExecutionContext,
        private readonly LoggerInterface       $logger,
    ) {}

    public function __invoke(AgentTaskMessage $message): void
    {
        $messengerContext = $this->messengerExecutionContext->getOrDefault();
        $attempt = $messengerContext['attempt'];
        $isRetry = $messengerContext['isRetry'];
        $receiverName = $messengerContext['receiverName'];
        $execution = $message->taskExecutionId !== null
            ? $this->taskExecutionService->findById($message->taskExecutionId)
            : null;

        $task  = $this->taskRepository->find(Uuid::fromString($message->taskId));
        $agent = $this->agentRepository->find(Uuid::fromString($message->agentId));

        if ($execution === null) {
            $this->logger->error('AgentTaskHandler: task execution not found', ['task_execution_id' => $message->taskExecutionId]);
            $this->logService->record(
                source: 'worker',
                category: 'error',
                level: 'error',
                title: 'Agent task execution not found',
                // Stored in DB for the in-app log UI, so the human-facing message stays in French.
                message: sprintf('Exécution introuvable pour le worker: %s', $message->taskExecutionId ?? 'n/a'),
                options: [
                    'task_id' => $message->taskId,
                    'agent_id' => $message->agentId,
                    'request_ref' => $message->requestRef,
                    'trace_ref' => $message->traceRef,
                    'context' => [
                        'skill_slug' => $message->skillSlug,
                        'messenger_attempt' => $attempt,
                        'messenger_retry_count' => max(0, $attempt - 1),
                        'messenger_is_retry' => $isRetry,
                        'messenger_receiver' => $receiverName,
                    ],
                ],
            );
            return;
        }

        if ($task === null) {
            $attemptEntity = $this->taskExecutionService->startAttempt($execution, $attempt, null, $message->requestRef, $receiverName);
            $willRetry = $attempt < $execution->getMaxAttempts();
            // Stored in DB for the in-app log UI, so the human-facing message stays in French.
            $this->taskExecutionService->markFailed($execution, $attemptEntity, sprintf('Tâche introuvable pour le worker: %s', $message->taskId), $willRetry, 'dispatch');
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
                    'context' => [
                        'skill_slug' => $message->skillSlug,
                        'task_execution_id' => (string) $execution->getId(),
                        'task_execution_attempt' => $attempt,
                        'messenger_attempt' => $attempt,
                        'messenger_retry_count' => max(0, $attempt - 1),
                        'messenger_is_retry' => $isRetry,
                        'messenger_receiver' => $receiverName,
                    ],
                ],
            );
            return;
        }

        if ($agent === null) {
            $attemptEntity = $this->taskExecutionService->startAttempt($execution, $attempt, null, $message->requestRef, $receiverName);
            $willRetry = $attempt < $execution->getMaxAttempts();
            // Stored in DB for the in-app log UI, so the human-facing message stays in French.
            $this->taskExecutionService->markFailed($execution, $attemptEntity, sprintf('Agent introuvable pour le worker: %s', $message->agentId), $willRetry, 'dispatch');
            if (!$willRetry) {
                // Stored in DB for the in-app log UI, so the human-facing message stays in French.
                $this->taskService->failExecution($task, sprintf('Agent introuvable pour le worker: %s', $message->agentId));
            }
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
                    'context' => [
                        'skill_slug' => $message->skillSlug,
                        'task_execution_id' => (string) $execution->getId(),
                        'task_execution_attempt' => $attempt,
                        'messenger_attempt' => $attempt,
                        'messenger_retry_count' => max(0, $attempt - 1),
                        'messenger_is_retry' => $isRetry,
                        'messenger_receiver' => $receiverName,
                    ],
                ],
            );
            return;
        }

        $attemptEntity = $this->taskExecutionService->startAttempt(
            execution: $execution,
            attemptNumber: $attempt,
            agent: $agent,
            requestRef: $message->requestRef,
            messengerReceiver: $receiverName,
        );

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
                    'context' => [
                        'skill_slug' => $message->skillSlug,
                        'task_execution_id' => (string) $execution->getId(),
                        'task_execution_attempt_id' => (string) $attemptEntity->getId(),
                        'messenger_attempt' => $attempt,
                        'messenger_retry_count' => max(0, $attempt - 1),
                        'messenger_is_retry' => $isRetry,
                        'messenger_receiver' => $receiverName,
                    ],
                ],
            );

            $this->executionService->execute($task, $agent, $message->skillSlug);
            $this->taskExecutionService->markSucceeded($execution, $attemptEntity);
        } catch (\Throwable $e) {
            $willRetry = $attempt < $execution->getMaxAttempts();
            $this->taskExecutionService->markFailed($execution, $attemptEntity, $e->getMessage(), $willRetry, 'execution');
            if (!$willRetry) {
                $this->taskService->failExecution($task, $e->getMessage());
            }
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
                    'task_execution_id' => (string) $execution->getId(),
                    'task_execution_attempt_id' => (string) $attemptEntity->getId(),
                    'task_execution_will_retry' => $willRetry,
                    'messenger_attempt' => $attempt,
                    'messenger_retry_count' => max(0, $attempt - 1),
                    'messenger_is_retry' => $isRetry,
                    'messenger_receiver' => $receiverName,
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
