<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaskLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TaskLogRepository::class)]
#[ORM\Table(name: 'task_log')]
class TaskLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    #[ORM\Column(length: 100)]
    private string $action;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $content = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Task $task, string $action, ?string $content = null)
    {
        $this->id        = Uuid::v7();
        $this->task      = $task;
        $this->action    = $action;
        $this->content   = $content;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid                      { return $this->id; }
    public function getTask(): Task                    { return $this->task; }
    public function getAction(): string                { return $this->action; }
    public function getContent(): ?string              { return $this->content; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
