<?php

declare(strict_types=1);

namespace App\Domain\Agent;

use Symfony\Component\Uid\Uuid;

/**
 * Un Agent est une instance d'IA configurée avec un connecteur et un modèle.
 * Il peut être assigné à un rôle dans une équipe.
 */
class Agent
{
    private Uuid $id;
    private string $name;
    private string $connectorName;  // Ex: "claude-api", "claude-cli"
    private AgentConfig $config;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $connectorName, AgentConfig $config)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->connectorName = $connectorName;
        $this->config = $config;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getConnectorName(): string { return $this->connectorName; }
    public function getConfig(): AgentConfig { return $this->config; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function updateConfig(AgentConfig $config): void
    {
        $this->config = $config;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function switchConnector(string $connectorName): void
    {
        $this->connectorName = $connectorName;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
