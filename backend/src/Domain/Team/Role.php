<?php

declare(strict_types=1);

namespace App\Domain\Team;

use Symfony\Component\Uid\Uuid;

/**
 * Un rôle dans une équipe (ex: Backend Dev, Reviewer, QA...).
 * Un rôle est associé à un skill qui définit le comportement de l'agent.
 */
class Role
{
    private Uuid $id;
    private string $name;
    private ?string $description;
    private string $skillSlug;   // Ex: "backend-dev", "code-reviewer"

    public function __construct(string $name, string $skillSlug, ?string $description = null)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->skillSlug = $skillSlug;
        $this->description = $description;
    }

    public function getId(): Uuid { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getSkillSlug(): string { return $this->skillSlug; }

    public function changeSkill(string $skillSlug): void
    {
        $this->skillSlug = $skillSlug;
    }
}
