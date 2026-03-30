<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExternalSystem;
use App\Repository\ExternalReferenceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Lien entre une entité métier (Ticket, TicketTask, Feature) et un système externe (GitHub, GitLab, Jira).
 * Respecte l'architecture hexagonale : les entités métier ne connaissent pas les systèmes externes.
 */
#[ORM\Entity(repositoryClass: ExternalReferenceRepository::class)]
#[ORM\Table(name: 'external_reference')]
#[ORM\UniqueConstraint(name: 'uniq_external_ref', columns: ['entity_type', 'entity_id', 'system'])]
class ExternalReference
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 50)]
    private string $entityType;

    #[ORM\Column(type: 'uuid')]
    private Uuid $entityId;

    #[ORM\Column(enumType: ExternalSystem::class)]
    private ExternalSystem $system;

    #[ORM\Column(length: 255)]
    private string $externalId;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $externalUrl = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    public function __construct(
        string         $entityType,
        Uuid           $entityId,
        ExternalSystem $system,
        string         $externalId,
        ?string        $externalUrl = null,
    ) {
        $this->id          = Uuid::v7();
        $this->entityType  = $entityType;
        $this->entityId    = $entityId;
        $this->system      = $system;
        $this->externalId  = $externalId;
        $this->externalUrl = $externalUrl;
    }

    public function getId(): Uuid                       { return $this->id; }
    public function getEntityType(): string             { return $this->entityType; }
    public function getEntityId(): Uuid                 { return $this->entityId; }
    public function getSystem(): ExternalSystem         { return $this->system; }
    public function getExternalId(): string             { return $this->externalId; }
    public function getExternalUrl(): ?string           { return $this->externalUrl; }
    public function getMetadata(): ?array               { return $this->metadata; }
    public function getSyncedAt(): ?\DateTimeImmutable  { return $this->syncedAt; }

    public function setExternalUrl(?string $url): static    { $this->externalUrl = $url; return $this; }
    public function setMetadata(?array $data): static       { $this->metadata = $data; return $this; }

    public function markSynced(): static
    {
        $this->syncedAt = new \DateTimeImmutable();
        return $this;
    }
}
