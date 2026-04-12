<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Module;
use App\Entity\Project;
use App\Entity\Role;
use App\Enum\AuditAction;
use App\Enum\DispatchMode;
use App\Repository\ModuleRepository;
use App\Repository\ProjectRepository;
use App\Repository\RoleRepository;
use App\Repository\TeamRepository;
use App\Repository\TicketRepository;
use App\Repository\WorkflowRepository;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Manages projects: CRUD, module management, team/workflow assignment, and dispatch mode transitions.
 */
class ProjectService
{
    /**
     * Initializes the service with its dependencies.
     */
    public function __construct(
        private readonly EntityService      $entityService,
        private readonly ProjectRepository  $projectRepository,
        private readonly ModuleRepository   $moduleRepository,
        private readonly RoleRepository     $roleRepository,
        private readonly TeamRepository     $teamRepository,
        private readonly TicketRepository   $ticketRepository,
        private readonly WorkflowRepository $workflowRepository,
        private readonly TicketTaskService  $ticketTaskService,
        private readonly RealtimeUpdateService $realtimeUpdateService,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * Creates a new project and optionally assigns a team.
     *
     * @param string      $name                 Project name
     * @param string|null $description          Optional description
     * @param string|null $repositoryUrl        Optional repository URL
     * @param string|null $teamId               Optional team UUID to assign
     * @param string|null $workflowId           Optional workflow UUID to assign
     * @param string|null $defaultTicketRoleId  Optional role UUID assigned automatically to new UserStory/Bug tickets
     */
    public function create(
        string $name,
        ?string $description = null,
        ?string $repositoryUrl = null,
        ?string $teamId = null,
        ?string $workflowId = null,
        DispatchMode $dispatchMode = DispatchMode::Auto,
        ?string $defaultTicketRoleId = null,
    ): Project
    {
        if ($workflowId === null || $workflowId === '') {
            throw new \LogicException($this->translator->trans('project.validation.workflow_required', [], 'app'));
        }

        if ($teamId === null || $teamId === '') {
            throw new \LogicException($this->translator->trans('project.validation.team_required', [], 'app'));
        }

        $team = $this->teamRepository->find(Uuid::fromString($teamId));
        if ($team === null) {
            throw new \LogicException($this->translator->trans('project.validation.team_invalid', [], 'app'));
        }

        $project = new Project($name, $description);
        $project
            ->setRepositoryUrl($repositoryUrl)
            ->setDispatchMode($dispatchMode)
            ->setTeam($team);

        $project->setWorkflow($this->resolveAssignableWorkflow(null, $workflowId));
        $project->setDefaultTicketRole($this->resolveDefaultTicketRole($defaultTicketRoleId));

        $this->entityService->create($project, AuditAction::ProjectCreated, ['name' => $name]);
        $this->realtimeUpdateService->publishProjectChanged($project, 'created');
        return $project;
    }

    /**
     * Updates an existing project's fields and optionally reassigns its team.
     *
     * @param Project     $project              Project to update
     * @param string      $name                 New name
     * @param string|null $description          New description (null clears it)
     * @param string|null $repositoryUrl        New repository URL (null clears it)
     * @param string|null $teamId               Team UUID to assign, or null to detach current team
     * @param string|null $workflowId           Workflow UUID to assign, or null to detach current workflow
     * @param string|null $defaultTicketRoleId  Role UUID to assign to new UserStory/Bug tickets, or null to clear
     */
    public function update(
        Project $project,
        string $name,
        ?string $description,
        ?string $repositoryUrl = null,
        ?string $teamId = null,
        ?string $workflowId = null,
        ?DispatchMode $dispatchMode = null,
        ?string $defaultTicketRoleId = null,
    ): Project
    {
        if ($workflowId === null || $workflowId === '') {
            throw new \LogicException($this->translator->trans('project.validation.workflow_required', [], 'app'));
        }

        $previousDispatchMode = $project->getDispatchMode();
        $nextDispatchMode = $dispatchMode ?? $previousDispatchMode;

        $project
            ->setName($name)
            ->setDescription($description)
            ->setRepositoryUrl($repositoryUrl)
            ->setDispatchMode($nextDispatchMode);

        $team = $teamId !== null ? $this->teamRepository->find(Uuid::fromString($teamId)) : null;
        $project->setTeam($team);

        $project->setWorkflow($this->resolveAssignableWorkflow($project, $workflowId));
        $project->setDefaultTicketRole($this->resolveDefaultTicketRole($defaultTicketRoleId));

        $this->entityService->update($project, AuditAction::ProjectUpdated, ['name' => $name]);
        $this->realtimeUpdateService->publishProjectChanged($project, 'updated');

        if ($previousDispatchMode === DispatchMode::Manual && $nextDispatchMode === DispatchMode::Auto && $project->getTeam() !== null) {
            foreach ($this->ticketRepository->findByProject($project) as $ticket) {
                $this->ticketTaskService->dispatchEligibleTasksForCurrentStep($ticket);
            }
        }

        return $project;
    }

    /**
     * Deletes a project and records the audit event.
     */
    public function delete(Project $project): void
    {
        $this->entityService->delete($project, AuditAction::ProjectDeleted);
    }

    /**
     * Creates a new module, attaches it to the project, and persists it.
     */
    public function addModule(Project $project, string $name, ?string $description = null, ?string $repositoryUrl = null, ?string $stack = null): Module
    {
        $module = new Module($project, $name, $description);
        $module->setRepositoryUrl($repositoryUrl)->setStack($stack);
        $project->addModule($module);
        $this->entityService->create($module, AuditAction::ModuleCreated, [
            'name'    => $name,
            'project' => (string) $project->getId(),
        ]);
        $this->realtimeUpdateService->publishProjectChanged($project, 'module_created');
        return $module;
    }

    /**
     * Updates a module's fields and persists the changes.
     */
    public function updateModule(Module $module, string $name, ?string $description, ?string $repositoryUrl, ?string $stack): Module
    {
        $module->setName($name)->setDescription($description)->setRepositoryUrl($repositoryUrl)->setStack($stack);
        $this->entityService->update($module, AuditAction::ModuleUpdated);
        $this->realtimeUpdateService->publishProjectChanged($module->getProject(), 'module_updated');
        return $module;
    }

    /**
     * Deletes a module and records the audit event.
     */
    public function deleteModule(Module $module): void
    {
        $project = $module->getProject();
        $this->entityService->delete($module, AuditAction::ModuleDeleted);
        $this->realtimeUpdateService->publishProjectChanged($project, 'module_deleted');
    }

    /**
     * Returns all projects.
     *
     * @return Project[]
     */
    public function findAll(): array
    {
        return $this->projectRepository->findAll();
    }

    /**
     * Finds a project by its UUID string identifier.
     */
    public function findById(string $id): ?Project
    {
        return $this->projectRepository->find(Uuid::fromString($id));
    }

    /**
     * Finds a module by its UUID string identifier.
     */
    public function findModuleById(string $id): ?Module
    {
        return $this->moduleRepository->find(Uuid::fromString($id));
    }

    /**
     * Resolves a role by its UUID string, or returns null when no ID is provided.
     * Throws a LogicException when the ID is provided but no matching role is found.
     */
    private function resolveDefaultTicketRole(?string $roleId): ?Role
    {
        if ($roleId === null) {
            return null;
        }

        $role = $this->roleRepository->find(Uuid::fromString($roleId));
        if ($role === null) {
            throw new \LogicException($this->translator->trans('project.validation.default_ticket_role_invalid', [], 'app'));
        }

        return $role;
    }

    private function resolveAssignableWorkflow(?Project $project, string $workflowId): ?\App\Entity\Workflow
    {
        $workflow = $this->workflowRepository->find(Uuid::fromString($workflowId));
        if ($workflow === null) {
            return null;
        }

        $currentWorkflowId = $project?->getWorkflow()?->getId()->toRfc4122();
        if (!$workflow->isActive() && $currentWorkflowId !== $workflowId) {
            throw new \LogicException($this->translator->trans('project.error.workflow_inactive', [], 'app'));
        }

        return $workflow;
    }
}
