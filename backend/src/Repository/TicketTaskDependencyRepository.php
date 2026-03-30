<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TicketTask;
use App\Entity\TicketTaskDependency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TicketTaskDependency>
 */
final class TicketTaskDependencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketTaskDependency::class);
    }

    /** @return TicketTaskDependency[] */
    public function findByTicketTask(TicketTask $ticketTask): array
    {
        return $this->findBy(['ticketTask' => $ticketTask]);
    }

    /** @return TicketTaskDependency[] */
    public function findByDependsOn(TicketTask $ticketTask): array
    {
        return $this->findBy(['dependsOn' => $ticketTask]);
    }

    /**
     * @param TicketTask[] $ticketTasks
     * @return array<string, TicketTaskDependency[]>
     */
    public function findGroupedByTicketTasks(array $ticketTasks): array
    {
        if ($ticketTasks === []) {
            return [];
        }

        $dependencies = $this->createQueryBuilder('d')
            ->andWhere('d.ticketTask IN (:ticketTasks)')
            ->setParameter('ticketTasks', $ticketTasks)
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($dependencies as $dependency) {
            $grouped[$dependency->getTicketTask()->getId()->toRfc4122()][] = $dependency;
        }

        return $grouped;
    }
}
