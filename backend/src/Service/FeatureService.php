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
use Symfony\Component\Uid\Uuid;

class FeatureService
{
    /**
     * Initialize the service with its required repository and entity service.
     */
    public function __construct(
        private readonly EntityService     $entityService,
        private readonly FeatureRepository $featureRepository,
    ) {}

    /**
     * Create a new feature for the given project and persist it with an audit trail.
     */
    public function create(Project $project, string $name, ?string $description = null): Feature
    {
        $feature = new Feature($project, $name, $description);
        $this->entityService->create($feature, AuditAction::FeatureCreated, [
            'name'    => $name,
            'project' => (string) $project->getId(),
        ]);
        return $feature;
    }

    /**
     * Update a feature's name, description, and status, then persist the changes.
     */
    public function update(Feature $feature, string $name, ?string $description, FeatureStatus $status): Feature
    {
        $feature->setName($name)->setDescription($description)->setStatus($status);
        $this->entityService->update($feature, AuditAction::FeatureUpdated);
        return $feature;
    }

    /**
     * Delete a feature and record the deletion in the audit log.
     */
    public function delete(Feature $feature): void
    {
        $this->entityService->delete($feature, AuditAction::FeatureDeleted);
    }

    /**
     * @return Feature[]
     */
    public function findByProject(Project $project): array
    {
        return $this->featureRepository->findByProject($project);
    }

    /**
     * Find a feature by its UUID string, returning null if not found.
     */
    public function findById(string $id): ?Feature
    {
        return $this->featureRepository->find(Uuid::fromString($id));
    }
}
