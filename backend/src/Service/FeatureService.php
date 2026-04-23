<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Dto\Input\Feature\CreateFeatureDto;
use App\Dto\Input\Feature\UpdateFeatureDto;
use App\Entity\Feature;
use App\Entity\Project;
use App\Enum\AuditAction;
use App\Repository\FeatureRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Manages features (epics) within projects: CRUD, status transitions, and ticket association.
 */
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
    public function create(Project $project, CreateFeatureDto $dto): Feature
    {
        $feature = new Feature($project, $dto->name, $dto->description);
        $this->entityService->create($feature, AuditAction::FeatureCreated, [
            'name'    => $dto->name,
            'project' => (string) $project->getId(),
        ]);
        return $feature;
    }

    /**
     * Update a feature's name, description, and status, then persist the changes.
     */
    public function update(Feature $feature, UpdateFeatureDto $dto): Feature
    {
        $feature->setName($dto->name ?? $feature->getName())
                ->setDescription($dto->description ?? $feature->getDescription())
                ->setStatus($dto->status ?? $feature->getStatus());
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
