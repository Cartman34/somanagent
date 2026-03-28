<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\Task;
use App\Entity\TaskExecution;
use App\Entity\TaskExecutionAttempt;
use App\Entity\TaskLog;
use App\Enum\TaskExecutionAttemptStatus;
use App\Enum\TaskExecutionStatus;
use App\Enum\TaskExecutionTrigger;
use App\Repository\TaskExecutionAttemptRepository;
use App\Repository\TaskExecutionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Persists task execution runs and their retry attempts independently from ticket logs.
 */
final class TaskExecutionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaskExecutionRepository $taskExecutionRepository,
        private readonly TaskExecutionAttemptRepository $taskExecutionAttemptRepository,
        private readonly int $messengerAgentTaskMaxRetries,
    ) {}

    /**
     * Creates a new execution run attached to a task.
     */
    public function createExecution(
        Task $task,
        ?Agent $requestedAgent,
        string $skillSlug,
        TaskExecutionTrigger $triggerType,
        ?string $requestRef = null,
        ?string $traceRef = null,
        ?string $workflowStepKey = null,
        ?int $maxAttempts = null,
    ): TaskExecution {
        $execution = new TaskExecution(
            task: $task,
            traceRef: $traceRef ?? Uuid::v7()->toRfc4122(),
            triggerType: $triggerType,
            maxAttempts: $maxAttempts ?? $this->getDefaultAsyncMaxAttempts(),
        );
        $execution
            ->setRequestedAgent($requestedAgent)
            ->setSkillSlug($skillSlug)
            ->setWorkflowStepKey($workflowStepKey)
            ->setRequestRef($requestRef);

        $this->em->persist($execution);
        $this->em->flush();

        return $execution;
    }

    /**
     * Starts or reuses the attempt matching the given attempt number for this execution.
     */
    public function startAttempt(
        TaskExecution $execution,
        int $attemptNumber,
        ?Agent $agent = null,
        ?string $requestRef = null,
        ?string $messengerReceiver = null,
    ): TaskExecutionAttempt {
        $attempt = $this->taskExecutionAttemptRepository->findOneByExecutionAndAttemptNumber($execution, $attemptNumber);
        if ($attempt === null) {
            $attempt = new TaskExecutionAttempt($execution, $attemptNumber);
            $this->em->persist($attempt);
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

        // Stored in DB for the in-app log UI, so the human-facing message stays in French.
        $this->em->persist($this->buildTaskLog(
            $execution->getTask(),
            'execution_attempt_started',
            sprintf(
                'Tentative %d/%d démarrée pour la trace %s.',
                $attemptNumber,
                $execution->getMaxAttempts(),
                $execution->getTraceRef(),
            ),
            $execution,
            $attempt,
            [
                'agentId' => $agent?->getId()->toRfc4122(),
                'requestRef' => $requestRef,
                'messengerReceiver' => $messengerReceiver,
            ],
        ));

        $this->em->flush();

        return $attempt;
    }

    /**
     * Marks the attempt and its parent execution as successfully completed.
     */
    public function markSucceeded(TaskExecution $execution, TaskExecutionAttempt $attempt): void
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

        // Stored in DB for the in-app log UI, so the human-facing message stays in French.
        $this->em->persist($this->buildTaskLog(
            $execution->getTask(),
            'execution_succeeded',
            sprintf(
                'Exécution réussie à la tentative %d pour la trace %s.',
                $attempt->getAttemptNumber(),
                $execution->getTraceRef(),
            ),
            $execution,
            $attempt,
        ));

        $this->em->flush();
    }

    /**
     * Marks an execution attempt as failed and records whether another retry will follow.
     */
    public function markFailed(
        TaskExecution $execution,
        TaskExecutionAttempt $attempt,
        string $errorMessage,
        bool $willRetry,
        ?string $errorScope = null,
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

        // Stored in DB for the in-app log UI, so the human-facing message stays in French.
        $this->em->persist($this->buildTaskLog(
            $execution->getTask(),
            'execution_attempt_failed',
            sprintf(
                'Tentative %d/%d en échec pour la trace %s: %s',
                $attempt->getAttemptNumber(),
                $execution->getMaxAttempts(),
                $execution->getTraceRef(),
                $errorMessage,
            ),
            $execution,
            $attempt,
            [
                'willRetry' => $willRetry,
                'errorScope' => $errorScope,
            ],
        ));

        if ($willRetry) {
            // Stored in DB for the in-app log UI, so the human-facing message stays in French.
            $this->em->persist($this->buildTaskLog(
                $execution->getTask(),
                'execution_retrying',
                sprintf(
                    'Retry prévu après la tentative %d/%d pour la trace %s.',
                    $attempt->getAttemptNumber(),
                    $execution->getMaxAttempts(),
                    $execution->getTraceRef(),
                ),
                $execution,
                $attempt,
            ));
        } else {
            // Stored in DB for the in-app log UI, so the human-facing message stays in French.
            $this->em->persist($this->buildTaskLog(
                $execution->getTask(),
                'execution_dead_letter',
                sprintf(
                    'Retries épuisés pour la trace %s après %d tentative%s.',
                    $execution->getTraceRef(),
                    $attempt->getAttemptNumber(),
                    $attempt->getAttemptNumber() > 1 ? 's' : '',
                ),
                $execution,
                $attempt,
                [
                    'errorScope' => $errorScope,
                ],
            ));
        }

        $this->em->flush();
    }

    /**
     * Records the human-readable dispatch entry associated with a new execution.
     */
    public function logDispatch(TaskExecution $execution, string $action, string $content, array $metadata = []): void
    {
        $this->em->persist($this->buildTaskLog(
            $execution->getTask(),
            $action,
            $content,
            $execution,
            null,
            $metadata,
        ));
        $this->em->flush();
    }

    /**
     * Returns executions for a task ordered from newest to oldest.
     *
     * @return TaskExecution[]
     */
    public function findByTask(Task $task): array
    {
        return $this->taskExecutionRepository->findByTask($task);
    }

    /**
     * Finds an execution by identifier so async workers can resume its tracking chain.
     */
    public function findById(string $id): ?TaskExecution
    {
        return $this->taskExecutionRepository->findById($id);
    }

    /**
     * Returns the default number of attempts for asynchronous agent task dispatches.
     */
    public function getDefaultAsyncMaxAttempts(): int
    {
        return max(1, $this->messengerAgentTaskMaxRetries + 1);
    }

    /**
     * Builds a TaskLog projection so ticket history stays readable without querying execution tables directly.
     */
    private function buildTaskLog(
        Task $task,
        string $action,
        string $content,
        TaskExecution $execution,
        ?TaskExecutionAttempt $attempt,
        array $metadata = [],
    ): TaskLog {
        return (new TaskLog($task, $action, $content))->setMetadata([
            'taskExecutionId' => $execution->getId()->toRfc4122(),
            'traceRef' => $execution->getTraceRef(),
            'triggerType' => $execution->getTriggerType()->value,
            'skillSlug' => $execution->getSkillSlug(),
            'attempt' => $attempt?->getAttemptNumber(),
            'attemptStatus' => $attempt?->getStatus()->value,
            'maxAttempts' => $execution->getMaxAttempts(),
            ...$metadata,
        ]);
    }
}
