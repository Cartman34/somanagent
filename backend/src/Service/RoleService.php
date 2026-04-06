<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Role;
use App\Enum\AuditAction;
use App\Repository\RoleRepository;
use App\Repository\SkillRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Manages specialization roles: CRUD, skill assignment, and agent association.
 */
class RoleService
{
    /**
     * Initialize the service with its required repositories and entity service.
     */
    public function __construct(
        private readonly EntityService  $entityService,
        private readonly RoleRepository $roleRepository,
        private readonly SkillRepository $skillRepository,
    ) {}

    /**
     * Create a new role and persist it with an audit trail.
     */
    public function create(string $slug, string $name, ?string $description = null): Role
    {
        $role = new Role($slug, $name, $description);
        $this->entityService->create($role, AuditAction::RoleCreated, ['slug' => $slug, 'name' => $name]);
        return $role;
    }

    /**
     * Update a role's slug, name, and description, then persist the changes.
     */
    public function update(Role $role, string $slug, string $name, ?string $description): Role
    {
        $role->setSlug($slug)->setName($name)->setDescription($description);
        $this->entityService->update($role, AuditAction::RoleUpdated);
        return $role;
    }

    /**
     * Delete a role and record the deletion in the audit log.
     */
    public function delete(Role $role): void
    {
        $this->entityService->delete($role, AuditAction::RoleDeleted);
    }

    /**
     * Add a skill to a role, throwing an exception if the skill does not exist.
     */
    public function addSkill(Role $role, string $skillId): void
    {
        $skill = $this->skillRepository->find(Uuid::fromString($skillId));
        if ($skill === null) {
            throw new \InvalidArgumentException("Skill not found: {$skillId}");
        }
        $role->addSkill($skill);
        $this->entityService->flush();
    }

    /**
     * Remove a skill from a role, throwing an exception if the skill does not exist.
     */
    public function removeSkill(Role $role, string $skillId): void
    {
        $skill = $this->skillRepository->find(Uuid::fromString($skillId));
        if ($skill === null) {
            throw new \InvalidArgumentException("Skill not found: {$skillId}");
        }
        $role->removeSkill($skill);
        $this->entityService->flush();
    }

    /**
     * @return Role[]
     */
    public function findAll(): array
    {
        return $this->roleRepository->findAll();
    }

    /**
     * Find a role by its UUID string, returning null if not found.
     */
    public function findById(string $id): ?Role
    {
        return $this->roleRepository->find(Uuid::fromString($id));
    }

    /**
     * Find a role by its slug, returning null if not found.
     */
    public function findBySlug(string $slug): ?Role
    {
        return $this->roleRepository->findOneBy(['slug' => $slug]);
    }
}
