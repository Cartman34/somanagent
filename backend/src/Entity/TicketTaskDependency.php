<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TicketTaskDependencyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TicketTaskDependencyRepository::class)]
#[ORM\Table(name: 'ticket_task_dependency')]
#[ORM\UniqueConstraint(columns: ['ticket_task_id', 'depends_on_id'])]
class TicketTaskDependency
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: TicketTask::class)]
    #[ORM\JoinColumn(name: 'ticket_task_id', nullable: false, onDelete: 'CASCADE')]
    private TicketTask $ticketTask;

    #[ORM\ManyToOne(targetEntity: TicketTask::class)]
    #[ORM\JoinColumn(name: 'depends_on_id', nullable: false, onDelete: 'CASCADE')]
    private TicketTask $dependsOn;

    public function __construct(TicketTask $ticketTask, TicketTask $dependsOn)
    {
        $this->id = Uuid::v7();
        $this->ticketTask = $ticketTask;
        $this->dependsOn = $dependsOn;
    }

    public function getId(): Uuid { return $this->id; }
    public function getTicketTask(): TicketTask { return $this->ticketTask; }
    public function getDependsOn(): TicketTask { return $this->dependsOn; }
}
