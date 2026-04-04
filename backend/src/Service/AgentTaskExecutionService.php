<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\AgentTaskExecution;
use App\Entity\AgentTaskExecutionAttempt;
use App\Entity\TicketTask;
use App\Enum\TaskExecutionAttemptStatus;
use App\Enum\TaskExecutionStatus;
use App\Enum\TaskExecutionTrigger;
use App\Repository\AgentTaskExecutionAttemptRepository;
use App\Repository\AgentTaskExecutionRepository;
use Symfony\Component\Uid\Uuid;

final class AgentTaskExecutionService
{
    /**
     * Initialises the service with its required repositories and configuration values.
     */
    public function __construct(
        private readonly EntityService                       $entityService,
        private readonly AgentTaskExecutionRepository        $executionRepository,
        private readonly AgentTaskExecutionAttemptRepository $attemptRepository,
        private readonly int                                 $messengerAgentTaskMaxRetries,
    ) {}

    /**
     * Creates and persists a new task execution record, associating it with the given ticket task if provided.
     */
    public function createExecution(
        ?TicketTask          $ticketTask,
        ?Agent               $requestedAgent,
        TaskExecutionTrigger $triggerType,
        ?string              $requestRef  = null,
        ?string              $traceRef    = null,
        ?int                 $maxAttempts = null,
    ): AgentTaskExecution {
        $action = $ticketTask?->getAgentAction();
        $execution = new AgentTaskExecution(
            traceRef: $traceRef ?? Uuid::v7()->toRfc4122(),
            triggerType: $triggerType,
            actionKey: $action?->getKey() ?? 'manual.unknown',
            maxAttempts: $maxAttempts ?? $this->getDefaultAsyncMaxAttempts(),
        );

        $execution
            ->setAgentAction($action)
            ->setActionLabel($action?->getLabel())
            ->setRoleSlug($action?->getRole()?->getSlug())
            ->setSkillSlug($action?->getSkill()?->getSlug())
            ->setRequestedAgent($requestedAgent)
            ->setRequestRef($requestRef);

        if ($ticketTask !== null) {
            $ticketTask->addExecution($execution);
        }

        $this->entityService->create($execution);

        return $execution;
    }

    /**
     * Starts or resumes an attempt for the given execution, marking both the attempt and execution as running.
     */
    public function startAttempt(
        AgentTaskExecution $execution,
        int                $attemptNumber,
        ?Agent             $agent             = null,
        ?string            $requestRef        = null,
        ?string            $messengerReceiver = null,
    ): AgentTaskExecutionAttempt {
        $attempt = $this->attemptRepository->findOneByExecutionAndAttemptNumber($execution, $attemptNumber);
        if ($attempt === null) {
            $attempt = new AgentTaskExecutionAttempt($execution, $attemptNumber);
            $this->entityService->persist($attempt);
        }

        $startedAt = new \DateTimeImmutable();
        $attempt
            ->setAgent($agent)
            ->setRequestRef($requestRef)
            ->setMessengerReceiver($messengerReceiver)
            ->setStartedAt($attempt->getStartedAt() ?? $startedAt)
            ->setFinishedAt(null)
            ->setStatus(TaskExecutionAttemptStatus::Running)
            ->setWillRetry(false)
            ->setErrorMessage(null)
            ->setErrorScope(null);

        $execution
            ->setEffectiveAgent($agent ?? $execution->getEffectiveAgent())
            ->setCurrentAttempt($attemptNumber)
            ->setStatus(TaskExecutionStatus::Running)
            ->setStartedAt($execution->getStartedAt() ?? $startedAt)
            ->setFinishedAt(null);

        $this->entityService->flush();

        return $attempt;
    }

    /**
     * Marks the execution and its attempt as succeeded and records the finish timestamp.
     */
    public function markSucceeded(AgentTaskExecution $execution, AgentTaskExecutionAttempt $attempt): void
    {
        $finishedAt = new \DateTimeImmutable();

        $attempt
            ->setStatus(TaskExecutionAttemptStatus::Succeeded)
            ->setWillRetry(false)
            ->setFinishedAt($finishedAt);

        $execution
            ->setStatus(TaskExecutionStatus::Succeeded)
            ->setFinishedAt($finishedAt)
            ->setLastErrorMessage(null)
            ->setLastErrorScope(null);

        $this->entityService->flush();
    }

    /**
     * Marks the attempt as failed and transitions the execution to retrying or dead-letter depending on willRetry.
     */
    public function markFailed(
        AgentTaskExecution         $execution,
        AgentTaskExecutionAttempt  $attempt,
        string                     $errorMessage,
        bool                       $willRetry,
        ?string                    $errorScope = null,
    ): void {
        $finishedAt = new \DateTimeImmutable();

        $attempt
            ->setStatus(TaskExecutionAttemptStatus::Failed)
            ->setWillRetry($willRetry)
            ->setFinishedAt($finishedAt)
            ->setErrorMessage($errorMessage)
            ->setErrorScope($errorScope);

        $execution
            ->setLastErrorMessage($errorMessage)
            ->setLastErrorScope($errorScope)
            ->setStatus($willRetry ? TaskExecutionStatus::Retrying : TaskExecutionStatus::DeadLetter);

        if (!$willRetry) {
            $execution->setFinishedAt($finishedAt);
        }

        $this->entityService->flush();
    }

    /**
     * Returns all executions associated with the given ticket task.
     *
     * @return AgentTaskExecution[]
     */
    public function findByTicketTask(TicketTask $ticketTask): array
    {
        return $this->executionRepository->findByTicketTask($ticketTask);
    }

    /**
     * Finds a single execution by its UUID string, returning null if not found.
     */
    public function findById(string $id): ?AgentTaskExecution
    {
        return $this->executionRepository->find(Uuid::fromString($id));
    }

    private function getDefaultAsyncMaxAttempts(): int
    {
        return max(1, $this->messengerAgentTaskMaxRetries + 1);
    }
}
