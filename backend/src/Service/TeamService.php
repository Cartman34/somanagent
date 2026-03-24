<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Role;
use App\Entity\Team;
use App\Enum\AuditAction;
use App\Repository\RoleRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class TeamService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TeamRepository         $teamRepository,
        private readonly RoleRepository         $roleRepository,
        private readonly AuditService           $audit,
    ) {}

    public function create(string $name, ?string $description = null): Team
    {
        $team = new Team($name, $description);
        $this->em->persist($team);
        $this->em->flush();
        $this->audit->log(AuditAction::TeamCreated, 'Team', (string) $team->getId(), ['name' => $name]);
        return $team;
    }

    public function update(Team $team, string $name, ?string $description): Team
    {
        $team->setName($name)->setDescription($description);
        $this->em->flush();
        $this->audit->log(AuditAction::TeamUpdated, 'Team', (string) $team->getId());
        return $team;
    }

    public function delete(Team $team): void
    {
        $id = (string) $team->getId();
        $this->em->remove($team);
        $this->em->flush();
        $this->audit->log(AuditAction::TeamDeleted, 'Team', $id);
    }

    public function addRole(Team $team, string $name, ?string $description = null, ?string $skillSlug = null): Role
    {
        $role = new Role($team, $name, $description, $skillSlug);
        $team->addRole($role);
        $this->em->persist($role);
        $this->em->flush();
        $this->audit->log(AuditAction::RoleAdded, 'Role', (string) $role->getId(), ['team' => (string) $team->getId(), 'name' => $name]);
        return $role;
    }

    public function updateRole(Role $role, string $name, ?string $description, ?string $skillSlug): Role
    {
        $role->setName($name)->setDescription($description)->setSkillSlug($skillSlug);
        $this->em->flush();
        return $role;
    }

    public function removeRole(Team $team, Role $role): void
    {
        $id = (string) $role->getId();
        $team->removeRole($role);
        $this->em->flush();
        $this->audit->log(AuditAction::RoleRemoved, 'Role', $id, ['team' => (string) $team->getId()]);
    }

    /** @return Team[] */
    public function findAll(): array
    {
        return $this->teamRepository->findAll();
    }

    public function findById(string $id): ?Team
    {
        return $this->teamRepository->find(Uuid::fromString($id));
    }

    public function findRoleById(string $id): ?Role
    {
        return $this->roleRepository->find(Uuid::fromString($id));
    }
}
