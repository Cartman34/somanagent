<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Module;
use App\Entity\Project;
use App\Enum\AuditAction;
use App\Repository\ModuleRepository;
use App\Repository\ProjectRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class ProjectService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectRepository      $projectRepository,
        private readonly ModuleRepository       $moduleRepository,
        private readonly TeamRepository         $teamRepository,
        private readonly AuditService           $audit,
    ) {}

    /**
     * Creates a new project and optionally assigns a team.
     *
     * @param string      $name          Project name
     * @param string|null $description   Optional description
     * @param string|null $repositoryUrl Optional repository URL
     * @param string|null $teamId        Optional team UUID to assign
     */
    public function create(string $name, ?string $description = null, ?string $repositoryUrl = null, ?string $teamId = null): Project
    {
        $project = new Project($name, $description);
        $project->setRepositoryUrl($repositoryUrl);

        if ($teamId !== null) {
            $team = $this->teamRepository->find(Uuid::fromString($teamId));
            if ($team !== null) {
                $project->setTeam($team);
            }
        }

        $this->em->persist($project);
        $this->em->flush();
        $this->audit->log(AuditAction::ProjectCreated, 'Project', (string) $project->getId(), ['name' => $name]);
        return $project;
    }

    /**
     * Updates an existing project's fields and optionally reassigns its team.
     *
     * @param Project     $project       Project to update
     * @param string      $name          New name
     * @param string|null $description   New description (null clears it)
     * @param string|null $repositoryUrl New repository URL (null clears it)
     * @param string|null $teamId        Team UUID to assign, or null to detach current team
     */
    public function update(Project $project, string $name, ?string $description, ?string $repositoryUrl = null, ?string $teamId = null): Project
    {
        $project->setName($name)->setDescription($description)->setRepositoryUrl($repositoryUrl);

        if ($teamId !== null) {
            $team = $this->teamRepository->find(Uuid::fromString($teamId));
            $project->setTeam($team);
        } else {
            $project->setTeam(null);
        }

        $this->em->flush();
        $this->audit->log(AuditAction::ProjectUpdated, 'Project', (string) $project->getId(), ['name' => $name]);
        return $project;
    }

    public function delete(Project $project): void
    {
        $id = (string) $project->getId();
        $this->em->remove($project);
        $this->em->flush();
        $this->audit->log(AuditAction::ProjectDeleted, 'Project', $id);
    }

    public function addModule(Project $project, string $name, ?string $description = null, ?string $repositoryUrl = null, ?string $stack = null): Module
    {
        $module = new Module($project, $name, $description);
        $module->setRepositoryUrl($repositoryUrl)->setStack($stack);
        $project->addModule($module);
        $this->em->persist($module);
        $this->em->flush();
        $this->audit->log(AuditAction::ModuleCreated, 'Module', (string) $module->getId(), ['name' => $name, 'project' => (string) $project->getId()]);
        return $module;
    }

    public function updateModule(Module $module, string $name, ?string $description, ?string $repositoryUrl, ?string $stack): Module
    {
        $module->setName($name)->setDescription($description)->setRepositoryUrl($repositoryUrl)->setStack($stack);
        $this->em->flush();
        $this->audit->log(AuditAction::ModuleUpdated, 'Module', (string) $module->getId());
        return $module;
    }

    public function deleteModule(Module $module): void
    {
        $id = (string) $module->getId();
        $this->em->remove($module);
        $this->em->flush();
        $this->audit->log(AuditAction::ModuleDeleted, 'Module', $id);
    }

    /** @return Project[] */
    public function findAll(): array
    {
        return $this->projectRepository->findAll();
    }

    public function findById(string $id): ?Project
    {
        return $this->projectRepository->find(Uuid::fromString($id));
    }

    public function findModuleById(string $id): ?Module
    {
        return $this->moduleRepository->find(Uuid::fromString($id));
    }
}
