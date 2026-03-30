<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Feature;
use App\Entity\Project;
use App\Entity\Ticket;
use App\Entity\Role;
use App\Enum\AuditAction;
use App\Enum\StoryStatus;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Enum\TaskType;
use App\Repository\FeatureRepository;
use App\Repository\RoleRepository;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class TicketService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketRepository $ticketRepository,
        private readonly FeatureRepository $featureRepository,
        private readonly RoleRepository $roleRepository,
        private readonly AuditService $audit,
    ) {}

    public function create(
        Project $project,
        TaskType $type,
        string $title,
        ?string $description = null,
        TaskPriority $priority = TaskPriority::Medium,
        ?string $featureId = null,
    ): Ticket {
        $ticket = new Ticket($project, $type, $title, $description, $priority);

        if ($type === TaskType::UserStory || $type === TaskType::Bug) {
            /** @var Role|null $productOwnerRole */
            $productOwnerRole = $this->roleRepository->findOneBy(['slug' => 'product-owner']);
            if ($productOwnerRole !== null) {
                $ticket->setAssignedRole($productOwnerRole);
            }
        }

        if ($featureId !== null) {
            $feature = $this->featureRepository->find(Uuid::fromString($featureId));
            if ($feature !== null) {
                $ticket->setFeature($feature);
            }
        }

        $this->em->persist($ticket);
        $this->em->flush();

        $this->audit->log(AuditAction::TaskCreated, 'Ticket', (string) $ticket->getId(), [
            'title' => $title,
            'type' => $type->value,
            'project' => (string) $project->getId(),
        ]);

        return $ticket;
    }

    public function update(
        Ticket $ticket,
        string $title,
        ?string $description,
        TaskPriority $priority,
        ?string $featureId,
    ): Ticket {
        $ticket
            ->setTitle($title)
            ->setDescription($description)
            ->setPriority($priority);

        $feature = $featureId ? $this->featureRepository->find(Uuid::fromString($featureId)) : null;
        $ticket->setFeature($feature);

        $this->em->flush();

        $this->audit->log(AuditAction::TaskUpdated, 'Ticket', (string) $ticket->getId());

        return $ticket;
    }

    public function changeStatus(Ticket $ticket, TaskStatus $status): Ticket
    {
        $previous = $ticket->getStatus();
        $ticket->setStatus($status);
        $this->em->flush();

        $this->audit->log(AuditAction::TaskStatusChanged, 'Ticket', (string) $ticket->getId(), [
            'from' => $previous->value,
            'to' => $status->value,
        ]);

        return $ticket;
    }

    public function transitionStory(Ticket $ticket, StoryStatus $next): Ticket
    {
        $ticket->transitionStoryTo($next);
        $this->em->flush();

        $this->audit->log(AuditAction::TaskUpdated, 'Ticket', (string) $ticket->getId(), [
            'story_status' => $next->value,
        ]);

        return $ticket;
    }

    public function delete(Ticket $ticket): void
    {
        $id = (string) $ticket->getId();
        $this->em->remove($ticket);
        $this->em->flush();

        $this->audit->log(AuditAction::TaskDeleted, 'Ticket', $id);
    }

    /** @return Ticket[] */
    public function findByProject(Project $project): array
    {
        return $this->ticketRepository->findByProject($project);
    }

    /** @return Ticket[] */
    public function findByFeature(Feature $feature): array
    {
        return $this->ticketRepository->findBy(['feature' => $feature], ['createdAt' => 'DESC']);
    }

    public function findById(string $id): ?Ticket
    {
        return $this->ticketRepository->find(Uuid::fromString($id));
    }
}
