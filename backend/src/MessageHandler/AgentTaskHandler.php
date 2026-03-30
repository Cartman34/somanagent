<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\AgentTaskMessage;
use App\Repository\AgentRepository;
use App\Repository\TicketTaskRepository;
use App\Service\AgentExecutionService;
use App\Service\AgentTaskExecutionService;
use App\Service\LogService;
use App\Service\MessengerExecutionContext;
use App\Service\TicketTaskService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Consumes AgentTaskMessage envelopes from Messenger and delegates execution to AgentExecutionService.
 */
#[AsMessageHandler]
final class AgentTaskHandler
{
    public function __construct(
        private readonly AgentRepository       $agentRepository,
        private readonly TicketTaskRepository  $ticketTaskRepository,
        private readonly AgentExecutionService $executionService,
        private readonly TicketTaskService     $ticketTaskService,
        private readonly AgentTaskExecutionService $agentTaskExecutionService,
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
        $agentTaskExecution = $this->agentTaskExecutionService->findById($message->agentTaskExecutionId);
        $ticketTask = $this->ticketTaskRepository->find(Uuid::fromString($message->ticketTaskId));
        $agent = $this->agentRepository->find(Uuid::fromString($message->agentId));

        if ($agentTaskExecution === null) {
            $this->logger->error('AgentTaskHandler: task execution not found', ['task_execution_id' => $message->agentTaskExecutionId]);
            $this->logService->record(
                source: 'worker',
                category: 'error',
                level: 'error',
                title: '',
                message: '',
                options: [
                    'title_i18n' => [
                        'domain' => 'logs',
                        'key' => 'logs.worker.error.task_execution_not_found.title',
                    ],
                    'message_i18n' => [
                        'domain' => 'logs',
                        'key' => 'logs.worker.error.task_execution_not_found.message',
                        'parameters' => [
                            '%taskExecutionId%' => $message->agentTaskExecutionId,
                        ],
                    ],
                    'task_id' => $message->ticketTaskId,
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

        if ($ticketTask === null) {
            $attemptEntity = $this->agentTaskExecutionService->startAttempt($agentTaskExecution, $attempt, null, $message->requestRef, $receiverName);
            $willRetry = $attempt < $agentTaskExecution->getMaxAttempts();
            $this->agentTaskExecutionService->markFailed($agentTaskExecution, $attemptEntity, sprintf('TicketTask introuvable pour le worker: %s', $message->ticketTaskId), $willRetry, 'dispatch');
            $this->logger->error('AgentTaskHandler: task not found', ['ticket_task_id' => $message->ticketTaskId]);
            $this->logService->record(
                source: 'worker',
                category: 'error',
                level: 'error',
                title: '',
                message: '',
                options: [
                    'title_i18n' => [
                        'domain' => 'logs',
                        'key' => 'logs.worker.error.task_not_found.title',
                    ],
                    'message_i18n' => [
                        'domain' => 'logs',
                        'key' => 'logs.worker.error.task_not_found.message',
                        'parameters' => [
                            '%taskId%' => $message->ticketTaskId,
                        ],
                    ],
                    'task_id' => $message->ticketTaskId,
                    'request_ref' => $message->requestRef,
                    'trace_ref' => $message->traceRef,
                    'context' => [
                        'skill_slug' => $message->skillSlug,
                        'task_execution_id' => (string) $agentTaskExecution->getId(),
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
            $attemptEntity = $this->agentTaskExecutionService->startAttempt($agentTaskExecution, $attempt, null, $message->requestRef, $receiverName);
            $willRetry = $attempt < $agentTaskExecution->getMaxAttempts();
            $this->agentTaskExecutionService->markFailed($agentTaskExecution, $attemptEntity, sprintf('Agent introuvable pour le worker: %s', $message->agentId), $willRetry, 'dispatch');
            if (!$willRetry) {
                $this->ticketTaskService->failExecution($ticketTask, sprintf('Agent introuvable pour le worker: %s', $message->agentId));
            }
            $this->logger->error('AgentTaskHandler: agent not found', ['agent_id' => $message->agentId]);
            $this->logService->record(
                source: 'worker',
                category: 'error',
                level: 'error',
                title: '',
                message: '',
                options: [
                    'title_i18n' => [
                        'domain' => 'logs',
                        'key' => 'logs.worker.error.agent_runtime_not_found.title',
                    ],
                    'message_i18n' => [
                        'domain' => 'logs',
                        'key' => 'logs.worker.error.agent_runtime_not_found.message',
                        'parameters' => [
                            '%agentId%' => $message->agentId,
                        ],
                    ],
                    'task_id' => $message->ticketTaskId,
                    'agent_id' => $message->agentId,
                    'request_ref' => $message->requestRef,
                    'trace_ref' => $message->traceRef,
                    'context' => [
                        'skill_slug' => $message->skillSlug,
                        'task_execution_id' => (string) $agentTaskExecution->getId(),
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

        $attemptEntity = $this->agentTaskExecutionService->startAttempt(
            execution: $agentTaskExecution,
            attemptNumber: $attempt,
            agent: $agent,
            requestRef: $message->requestRef,
            messengerReceiver: $receiverName,
        );

        $projectId = $ticketTask->getTicket()->getProject()->getId()->toRfc4122();
        $displayTitle = $ticketTask->getTitle();
        $runtimeTaskId = $ticketTask->getId()->toRfc4122();
        $executionId = (string) $agentTaskExecution->getId();

        try {
            $this->logService->record(
                source: 'worker',
                category: 'runtime',
                level: 'info',
                title: '',
                message: '',
                options: [
                    'title_i18n' => [
                        'domain' => 'logs',
                        'key' => 'logs.worker.runtime.execution_started.title',
                    ],
                    'message_i18n' => [
                        'domain' => 'logs',
                        'key' => 'logs.worker.runtime.execution_started.message',
                        'parameters' => [
                            '%taskTitle%' => $displayTitle,
                            '%agentName%' => $agent->getName(),
                        ],
                    ],
                    'project_id' => $projectId,
                    'task_id' => $runtimeTaskId,
                    'agent_id' => (string) $agent->getId(),
                    'request_ref' => $message->requestRef,
                    'trace_ref' => $message->traceRef,
                    'context' => [
                        'skill_slug' => $message->skillSlug,
                        'task_execution_id' => $executionId,
                        'task_execution_attempt_id' => (string) $attemptEntity->getId(),
                        'messenger_attempt' => $attempt,
                        'messenger_retry_count' => max(0, $attempt - 1),
                        'messenger_is_retry' => $isRetry,
                        'messenger_receiver' => $receiverName,
                    ],
                ],
            );

            $this->executionService->executeTicketTask($ticketTask, $agent, $message->skillSlug);
            $this->agentTaskExecutionService->markSucceeded($agentTaskExecution, $attemptEntity);
            $this->ticketTaskService->dispatchReadyDependents($ticketTask);
        } catch (\Throwable $e) {
            $maxAttempts = $agentTaskExecution->getMaxAttempts();
            $willRetry = $attempt < $maxAttempts;

            $this->agentTaskExecutionService->markFailed($agentTaskExecution, $attemptEntity, $e->getMessage(), $willRetry, 'execution');

            if (!$willRetry) {
                $this->ticketTaskService->failExecution($ticketTask, $e->getMessage());
            }
            $this->logService->recordError('worker', '', $e, [
                'title_i18n' => [
                    'domain' => 'logs',
                    'key' => 'logs.worker.error.execution_failed.title',
                ],
                'project_id' => $projectId,
                'task_id' => $runtimeTaskId,
                'agent_id' => (string) $agent->getId(),
                'request_ref' => $message->requestRef,
                'trace_ref' => $message->traceRef,
                'context' => [
                    'task_title' => $displayTitle,
                    'agent_name' => $agent->getName(),
                    'skill_slug' => $message->skillSlug,
                    'task_execution_id' => $executionId,
                    'task_execution_attempt_id' => (string) $attemptEntity->getId(),
                    'task_execution_will_retry' => $willRetry,
                    'messenger_attempt' => $attempt,
                    'messenger_retry_count' => max(0, $attempt - 1),
                    'messenger_is_retry' => $isRetry,
                    'messenger_receiver' => $receiverName,
                ],
            ]);

            $this->logger->error('AgentTaskHandler: execution failed', [
                'task'      => $displayTitle,
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
