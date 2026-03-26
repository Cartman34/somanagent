<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExternalReference;
use App\Enum\ExternalSystem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ExternalReference>
 */
class ExternalReferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalReference::class);
    }

    public function findOne(string $entityType, Uuid $entityId, ExternalSystem $system): ?ExternalReference
    {
        return $this->findOneBy([
            'entityType' => $entityType,
            'entityId'   => $entityId,
            'system'     => $system,
        ]);
    }

    /** @return ExternalReference[] */
    public function findForEntity(string $entityType, Uuid $entityId): array
    {
        return $this->findBy(['entityType' => $entityType, 'entityId' => $entityId]);
    }
}
