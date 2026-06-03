<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Repository;

use Sowapps\SoManAgent\Enum\ExternalSystem;
use Sowapps\SoManAgent\Entity\ExternalReference;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ExternalReference>
 */
class ExternalReferenceRepository extends ServiceEntityRepository
{
    /**
     * Initializes the repository with the Doctrine ManagerRegistry.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalReference::class);
    }

    /**
     * Finds a single external reference matching the given entity and system.
     */
    public function findOne(string $entityType, Uuid $entityId, ExternalSystem $system): ?ExternalReference
    {
        return $this->findOneBy([
            'entityType' => $entityType,
            'entityId'   => $entityId,
            'system'     => $system,
        ]);
    }

    /**
     * @return ExternalReference[]
     */
    public function findForEntity(string $entityType, Uuid $entityId): array
    {
        return $this->findBy(['entityType' => $entityType, 'entityId' => $entityId]);
    }
}
