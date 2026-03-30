<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Role;
use App\Entity\Skill;
use App\Enum\AuditAction;
use App\Repository\RoleRepository;
use App\Repository\SkillRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class RoleService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RoleRepository         $roleRepository,
        private readonly SkillRepository        $skillRepository,
        private readonly AuditService           $audit,
    ) {}

    public function create(string $slug, string $name, ?string $description = null): Role
    {
        $role = new Role($slug, $name, $description);
        $this->em->persist($role);
        $this->em->flush();
        $this->audit->log(AuditAction::RoleCreated, 'Role', (string) $role->getId(), ['slug' => $slug, 'name' => $name]);
        return $role;
    }

    public function update(Role $role, string $slug, string $name, ?string $description): Role
    {
        $role->setSlug($slug)->setName($name)->setDescription($description);
        $this->em->flush();
        $this->audit->log(AuditAction::RoleUpdated, 'Role', (string) $role->getId());
        return $role;
    }

    public function delete(Role $role): void
    {
        $id = (string) $role->getId();
        $this->em->remove($role);
        $this->em->flush();
        $this->audit->log(AuditAction::RoleDeleted, 'Role', $id);
    }

    public function addSkill(Role $role, string $skillId): void
    {
        $skill = $this->skillRepository->find(Uuid::fromString($skillId));
        if ($skill === null) {
            throw new \InvalidArgumentException("Skill introuvable : {$skillId}");
        }
        $role->addSkill($skill);
        $this->em->flush();
    }

    public function removeSkill(Role $role, string $skillId): void
    {
        $skill = $this->skillRepository->find(Uuid::fromString($skillId));
        if ($skill === null) {
            throw new \InvalidArgumentException("Skill introuvable : {$skillId}");
        }
        $role->removeSkill($skill);
        $this->em->flush();
    }

    /** @return Role[] */
    public function findAll(): array
    {
        return $this->roleRepository->findAll();
    }

    public function findById(string $id): ?Role
    {
        return $this->roleRepository->find(Uuid::fromString($id));
    }

    public function findBySlug(string $slug): ?Role
    {
        return $this->roleRepository->findOneBy(['slug' => $slug]);
    }
}
