<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Workflow;
use App\Enum\AuditAction;
use App\Enum\WorkflowTrigger;
use App\Repository\TeamRepository;
use App\Repository\WorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class WorkflowService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkflowRepository     $workflowRepository,
        private readonly TeamRepository         $teamRepository,
        private readonly AuditService           $audit,
    ) {}

    public function create(
        string          $name,
        WorkflowTrigger $trigger     = WorkflowTrigger::Manual,
        ?string         $description = null,
        ?string         $teamId      = null,
    ): Workflow {
        $workflow = new Workflow($name, $trigger, $description);

        if ($teamId !== null) {
            $team = $this->teamRepository->find(Uuid::fromString($teamId));
            if ($team !== null) {
                $workflow->setTeam($team);
            }
        }

        $this->em->persist($workflow);
        $this->em->flush();
        $this->audit->log(AuditAction::WorkflowCreated, 'Workflow', (string) $workflow->getId(), ['name' => $name]);

        return $workflow;
    }

    public function update(
        Workflow        $workflow,
        string          $name,
        WorkflowTrigger $trigger,
        ?string         $description = null,
        ?string         $teamId      = null,
    ): Workflow {
        $workflow->setName($name)
            ->setTrigger($trigger)
            ->setDescription($description);

        if ($teamId !== null) {
            $team = $this->teamRepository->find(Uuid::fromString($teamId));
            $workflow->setTeam($team ?? null);
        } else {
            $workflow->setTeam(null);
        }

        $this->em->flush();
        $this->audit->log(AuditAction::WorkflowUpdated, 'Workflow', (string) $workflow->getId());

        return $workflow;
    }

    public function validate(Workflow $workflow): Workflow
    {
        $workflow->validate();
        $this->em->flush();
        $this->audit->log(AuditAction::WorkflowUpdated, 'Workflow', (string) $workflow->getId(), ['status' => 'validated']);
        return $workflow;
    }

    public function delete(Workflow $workflow): void
    {
        $id = (string) $workflow->getId();
        $this->em->remove($workflow);
        $this->em->flush();
        $this->audit->log(AuditAction::WorkflowDeleted, 'Workflow', $id);
    }

    /** @return Workflow[] */
    public function findAll(): array
    {
        return $this->workflowRepository->findBy([], ['createdAt' => 'DESC']);
    }

    public function findById(string $id): ?Workflow
    {
        return $this->workflowRepository->find(Uuid::fromString($id));
    }
}
