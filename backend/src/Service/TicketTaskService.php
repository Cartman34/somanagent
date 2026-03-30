<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\AgentAction;
use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Entity\TicketTaskDependency;
use App\Enum\AuditAction;
use App\Enum\TaskExecutionTrigger;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Message\AgentTaskMessage;
use App\Repository\AgentActionRepository;
use App\Repository\AgentRepository;
use App\Repository\AgentTaskExecutionRepository;
use App\Repository\TicketTaskDependencyRepository;
use App\Repository\TicketTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class TicketTaskService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketTaskRepository $ticketTaskRepository,
        private readonly TicketTaskDependencyRepository $ticketTaskDependencyRepository,
        private readonly AgentTaskExecutionRepository $agentTaskExecutionRepository,
        private readonly AgentActionRepository $agentActionRepository,
        private readonly AgentRepository $agentRepository,
        private readonly AgentTaskExecutionService $agentTaskExecutionService,
        private readonly TicketLogService $ticketLogService,
        private readonly RequestCorrelationService $requestCorrelation,
        private readonly MessageBusInterface $bus,
        private readonly AuditService $audit,
    ) {}

    public function create(
        Ticket $ticket,
        string $actionKey,
        string $title,
        ?string $description = null,
        TaskPriority $priority = TaskPriority::Medium,
        ?string $parentId = null,
        ?string $assignedAgentId = null,
    ): TicketTask {
        $action = $this->requireAction($actionKey);
        $task = new TicketTask($ticket, $action, $title, $description, $priority);
        $task->setAssignedRole($action->getRole());

        if ($parentId !== null) {
            $parent = $this->ticketTaskRepository->find(Uuid::fromString($parentId));
            if ($parent !== null) {
                $task->setParent($parent);
            }
        }

        if ($assignedAgentId !== null) {
            $agent = $this->agentRepository->find(Uuid::fromString($assignedAgentId));
            if ($agent !== null) {
                $task->setAssignedAgent($agent);
            }
        }

        if ($task->getAssignedAgent() === null) {
            $task->setAssignedAgent($this->resolveAgentForTask($task));
        }

        $this->em->persist($task);
        $this->em->flush();

        $this->audit->log(AuditAction::TaskCreated, 'TicketTask', (string) $task->getId(), [
            'title' => $title,
            'ticket' => (string) $ticket->getId(),
            'actionKey' => $action->getKey(),
        ]);

        return $task;
    }

    public function update(
        TicketTask $task,
        string $title,
        ?string $description,
        TaskPriority $priority,
        ?string $actionKey,
        ?string $assignedAgentId,
    ): TicketTask {
        $task
            ->setTitle($title)
            ->setDescription($description)
            ->setPriority($priority);

        if ($actionKey !== null && $actionKey !== '') {
            $action = $this->requireAction($actionKey);
            $task
                ->setAgentAction($action)
                ->setAssignedRole($action->getRole())
                ->setAssignedAgent(null);
        }

        $agent = $assignedAgentId ? $this->agentRepository->find(Uuid::fromString($assignedAgentId)) : null;
        $task->setAssignedAgent($agent ?? $this->resolveAgentForTask($task));

        $this->em->flush();

        $this->audit->log(AuditAction::TaskUpdated, 'TicketTask', (string) $task->getId());

        return $task;
    }

    /**
     * @return array{agent: Agent, skill: string, executionId: string}
     */
    public function execute(TicketTask $task, ?Agent $agent = null, TaskExecutionTrigger $triggerType = TaskExecutionTrigger::Manual): array
    {
        $activeExecution = $this->agentTaskExecutionRepository->findActiveByTicketTask($task);
        if ($activeExecution !== null) {
            throw new \RuntimeException('Une exécution agent est déjà active pour cette tâche.');
        }

        $skillSlug = $task->getAgentAction()->getSkill()?->getSlug();
        if ($skillSlug === null) {
            throw new \RuntimeException(sprintf('L’action "%s" ne définit aucun skill exécutable.', $task->getAgentAction()->getKey()));
        }

        $resolvedAgent = $agent ?? $task->getAssignedAgent() ?? $this->resolveAgentForTask($task);
        if ($resolvedAgent === null) {
            throw new \RuntimeException(sprintf('Aucun agent actif disponible pour l’action "%s".', $task->getAgentAction()->getKey()));
        }

        $requestRef = $this->requestCorrelation->getCurrentRequestRef();
        $traceRef = Uuid::v7()->toRfc4122();
        $hasExecutionHistory = !$task->getExecutions()->isEmpty();

        $task
            ->setAssignedRole($task->getAgentAction()->getRole())
            ->setAssignedAgent($resolvedAgent)
            ->setStatus(TaskStatus::InProgress);

        if ($task->getStatus() !== TaskStatus::Done) {
            $task->setProgress(0);
        }

        $execution = $this->agentTaskExecutionService->createExecution(
            ticketTask: $task,
            requestedAgent: $resolvedAgent,
            triggerType: $triggerType,
            requestRef: $requestRef,
            traceRef: $traceRef,
        );

        $this->ticketLogService->log(
            ticket: $task->getTicket(),
            action: $triggerType === TaskExecutionTrigger::Manual && $hasExecutionHistory ? 'execution_redispatched' : 'execution_dispatched',
            content: sprintf(
                'Tâche dispatchée vers %s avec le skill %s',
                $resolvedAgent->getName(),
                $skillSlug,
            ),
            ticketTask: $task,
            metadata: [
                'agentId' => (string) $resolvedAgent->getId(),
                'agentName' => $resolvedAgent->getName(),
                'skillSlug' => $skillSlug,
                'roleSlug' => $task->getAgentAction()->getRole()?->getSlug(),
                'actionKey' => $task->getAgentAction()->getKey(),
                'executionId' => (string) $execution->getId(),
                'triggerType' => $triggerType->value,
            ],
        );

        $this->bus->dispatch(new AgentTaskMessage(
            ticketTaskId: (string) $task->getId(),
            agentId: (string) $resolvedAgent->getId(),
            skillSlug: $skillSlug,
            agentTaskExecutionId: (string) $execution->getId(),
            requestRef: $requestRef,
            traceRef: $traceRef,
        ));

        $this->em->flush();

        return [
            'agent' => $resolvedAgent,
            'skill' => $skillSlug,
            'executionId' => (string) $execution->getId(),
        ];
    }

    /**
     * @return array{agent: Agent, skill: string, executionId: string}
     */
    public function resume(TicketTask $task, ?Agent $agent = null): array
    {
        return $this->execute($task, $agent, TaskExecutionTrigger::Manual);
    }

    public function dispatchReadyDependents(TicketTask $completedTask): int
    {
        if ($completedTask->getStatus() !== TaskStatus::Done) {
            return 0;
        }

        $dispatched = 0;
        $dependencies = $this->ticketTaskDependencyRepository->findByDependsOn($completedTask);

        foreach ($dependencies as $dependency) {
            $dependentTask = $dependency->getTicketTask();
            if ($dependentTask->getStatus() === TaskStatus::Done) {
                continue;
            }

            if (!$this->areDependenciesSatisfied($dependentTask)) {
                continue;
            }

            if ($this->agentTaskExecutionRepository->findActiveByTicketTask($dependentTask) !== null) {
                continue;
            }

            try {
                $this->execute($dependentTask, triggerType: TaskExecutionTrigger::Auto);
                ++$dispatched;
            } catch (\Throwable $e) {
                $this->ticketLogService->log(
                    ticket: $dependentTask->getTicket(),
                    action: 'execution_dispatch_error',
                    content: $e->getMessage(),
                    ticketTask: $dependentTask,
                    metadata: [
                        'dependsOnTaskId' => (string) $completedTask->getId(),
                        'dependsOnTaskTitle' => $completedTask->getTitle(),
                    ],
                );
            }
        }

        $this->em->flush();

        return $dispatched;
    }

    /** @return Agent[] */
    public function availableAgents(TicketTask $task): array
    {
        $action = $task->getAgentAction();
        $team = $task->getTicket()->getProject()->getTeam();
        $roleSlug = $action->getRole()?->getSlug();
        $skillSlug = $action->getSkill()?->getSlug();

        if ($skillSlug !== null) {
            $agents = $team !== null
                ? $this->agentRepository->findActiveBySkillSlugAndTeam($skillSlug, $team)
                : $this->agentRepository->findActiveBySkillSlug($skillSlug);

            if ($roleSlug !== null) {
                $agents = array_values(array_filter(
                    $agents,
                    static fn(Agent $agent): bool => $agent->getRole()?->getSlug() === $roleSlug,
                ));
            }

            if ($agents !== []) {
                return $agents;
            }
        }

        if ($roleSlug === null) {
            return [];
        }

        return $team !== null
            ? $this->agentRepository->findActiveByRoleSlugAndTeam($roleSlug, $team)
            : $this->agentRepository->findActiveByRoleSlug($roleSlug);
    }

    public function failExecution(TicketTask $task, string $message): TicketTask
    {
        $previous = $task->getStatus();
        $task
            ->setStatus(TaskStatus::Backlog)
            ->setProgress(0);

        $this->em->flush();

        if ($previous !== TaskStatus::Backlog) {
            $this->audit->log(AuditAction::TaskStatusChanged, 'TicketTask', (string) $task->getId(), [
                'from' => $previous->value,
                'to' => TaskStatus::Backlog->value,
                'reason' => 'execution_error',
                'message' => $message,
            ]);
        }

        $this->audit->log(AuditAction::TaskUpdated, 'TicketTask', (string) $task->getId(), [
            'execution_error' => $message,
        ]);

        return $task;
    }

    public function changeStatus(TicketTask $task, TaskStatus $status): TicketTask
    {
        $previous = $task->getStatus();
        $task->setStatus($status);
        if ($status === TaskStatus::Done) {
            $task->setProgress(100);
        }

        $this->em->flush();

        $this->audit->log(AuditAction::TaskStatusChanged, 'TicketTask', (string) $task->getId(), [
            'from' => $previous->value,
            'to' => $status->value,
        ]);

        return $task;
    }

    public function updateProgress(TicketTask $task, int $progress): TicketTask
    {
        $task->setProgress($progress);
        $this->em->flush();

        $this->audit->log(AuditAction::TaskProgressUpdated, 'TicketTask', (string) $task->getId(), ['progress' => $progress]);

        return $task;
    }

    public function reprioritize(TicketTask $task, TaskPriority $priority): TicketTask
    {
        $previous = $task->getPriority();
        $task->setPriority($priority);
        $this->em->flush();

        $this->audit->log(AuditAction::TaskReprioritized, 'TicketTask', (string) $task->getId(), [
            'from' => $previous->value,
            'to' => $priority->value,
        ]);

        return $task;
    }

    public function requestValidation(TicketTask $task, ?string $comment = null): TicketTask
    {
        $task->setStatus(TaskStatus::Review);
        $this->em->flush();

        $this->audit->log(AuditAction::TaskValidationAsked, 'TicketTask', (string) $task->getId(), ['comment' => $comment]);

        return $task;
    }

    public function validate(TicketTask $task): TicketTask
    {
        $task->setStatus(TaskStatus::Done)->setProgress(100);
        $this->em->flush();

        $this->audit->log(AuditAction::TaskValidated, 'TicketTask', (string) $task->getId());

        return $task;
    }

    public function reject(TicketTask $task, ?string $reason = null): TicketTask
    {
        $task->setStatus(TaskStatus::InProgress);
        $this->em->flush();

        $this->audit->log(AuditAction::TaskRejected, 'TicketTask', (string) $task->getId(), ['reason' => $reason]);

        return $task;
    }

    public function addDependency(TicketTask $task, TicketTask $dependsOn): TicketTaskDependency
    {
        $dependency = new TicketTaskDependency($task, $dependsOn);
        $this->em->persist($dependency);
        $this->em->flush();

        return $dependency;
    }

    /** @return TicketTask[] */
    public function findByTicket(Ticket $ticket): array
    {
        return $this->ticketTaskRepository->findByTicket($ticket);
    }

    /** @return TicketTask[] */
    public function findRootsByTicket(Ticket $ticket): array
    {
        return $this->ticketTaskRepository->findRootsByTicket($ticket);
    }

    /** @return TicketTask[] */
    public function findChildren(TicketTask $task): array
    {
        return $this->ticketTaskRepository->findChildren($task);
    }

    /**
     * @param TicketTask[] $tasks
     * @return array<string, TicketTask[]>
     */
    public function findChildrenGroupedByParent(array $tasks): array
    {
        return $this->ticketTaskRepository->findChildrenGroupedByParent($tasks);
    }

    public function findById(string $id): ?TicketTask
    {
        return $this->ticketTaskRepository->find(Uuid::fromString($id));
    }

    public function findAssignedAgent(TicketTask $task): ?Agent
    {
        return $task->getAssignedAgent();
    }

    public function delete(TicketTask $task): void
    {
        $id = (string) $task->getId();
        $this->em->remove($task);
        $this->em->flush();

        $this->audit->log(AuditAction::TaskDeleted, 'TicketTask', $id);
    }

    private function requireAction(string $actionKey): AgentAction
    {
        $action = $this->agentActionRepository->findOneByKey($actionKey);
        if ($action === null) {
            throw new \InvalidArgumentException(sprintf('Unknown agent action "%s".', $actionKey));
        }

        return $action;
    }

    private function resolveAgentForTask(TicketTask $task): ?Agent
    {
        if ($task->getAssignedAgent() !== null && $task->getAssignedAgent()->isActive()) {
            return $task->getAssignedAgent();
        }

        $agents = $this->availableAgents($task);

        return $agents[0] ?? null;
    }

    private function areDependenciesSatisfied(TicketTask $task): bool
    {
        foreach ($this->ticketTaskDependencyRepository->findByTicketTask($task) as $dependency) {
            if ($dependency->getDependsOn()->getStatus() !== TaskStatus::Done) {
                return false;
            }
        }

        return true;
    }
}
