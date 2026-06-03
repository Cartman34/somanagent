<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Entity;

use Sowapps\SoManAgent\Repository\ExternalReferenceRepository;
use Sowapps\SoManAgent\Enum\ExternalSystem;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Links a business entity (Ticket, TicketTask, Feature) to an external system (GitHub, GitLab, Jira).
 * Business entities remain unaware of external systems, in line with the hexagonal architecture.
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

    /**
     * @var ?array<string, mixed>
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    /**
     * Creates an external reference linking a business entity to an external system record.
     */
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

    /**
     * Returns the reference identifier.
     */
    public function getId(): Uuid                       { return $this->id; }

    /**
     * Returns the type of the linked business entity.
     */
    public function getEntityType(): string             { return $this->entityType; }

    /**
     * Returns the identifier of the linked business entity.
     */
    public function getEntityId(): Uuid                 { return $this->entityId; }

    /**
     * Returns the external system this reference points to.
     */
    public function getSystem(): ExternalSystem         { return $this->system; }

    /**
     * Returns the identifier of the record in the external system.
     */
    public function getExternalId(): string             { return $this->externalId; }

    /**
     * Returns the URL of the record in the external system, if any.
     */
    public function getExternalUrl(): ?string           { return $this->externalUrl; }

    /**
     * @return ?array<string, mixed>
     */
    public function getMetadata(): ?array               { return $this->metadata; }

    /**
     * Returns when the reference was last synchronised, if ever.
     */
    public function getSyncedAt(): ?\DateTimeImmutable  { return $this->syncedAt; }

    /**
     * Updates the URL of the record in the external system.
     */
    public function setExternalUrl(?string $url): static    { $this->externalUrl = $url; return $this; }

    /**
     * @param ?array<string, mixed> $data
     */
    public function setMetadata(?array $data): static       { $this->metadata = $data; return $this; }

    /**
     * Marks the reference as synchronised at the current timestamp.
     */
    public function markSynced(): static
    {
        $this->syncedAt = new \DateTimeImmutable();
        return $this;
    }
}
