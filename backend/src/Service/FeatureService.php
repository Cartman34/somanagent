<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Feature;
use App\Entity\Project;
use App\Enum\AuditAction;
use App\Enum\FeatureStatus;
use App\Repository\FeatureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class FeatureService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FeatureRepository      $featureRepository,
        private readonly AuditService           $audit,
    ) {}

    public function create(Project $project, string $name, ?string $description = null): Feature
    {
        $feature = new Feature($project, $name, $description);
        $this->em->persist($feature);
        $this->em->flush();
        $this->audit->log(AuditAction::FeatureCreated, 'Feature', (string) $feature->getId(), [
            'name'    => $name,
            'project' => (string) $project->getId(),
        ]);
        return $feature;
    }

    public function update(Feature $feature, string $name, ?string $description, FeatureStatus $status): Feature
    {
        $feature->setName($name)->setDescription($description)->setStatus($status);
        $this->em->flush();
        $this->audit->log(AuditAction::FeatureUpdated, 'Feature', (string) $feature->getId());
        return $feature;
    }

    public function delete(Feature $feature): void
    {
        $id = (string) $feature->getId();
        $this->em->remove($feature);
        $this->em->flush();
        $this->audit->log(AuditAction::FeatureDeleted, 'Feature', $id);
    }

    /** @return Feature[] */
    public function findByProject(Project $project): array
    {
        return $this->featureRepository->findByProject($project);
    }

    public function findById(string $id): ?Feature
    {
        return $this->featureRepository->find(Uuid::fromString($id));
    }
}
