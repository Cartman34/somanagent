<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use Symfony\Component\Uid\Uuid;

/**
 * Une étape d'un workflow : quel rôle, quel skill, quelle entrée, quelle sortie.
 */
class Step
{
    private Uuid $id;
    private string $stepKey;       // identifiant unique dans le workflow (ex: "review")
    private string $name;
    private string $roleId;        // Rôle de l'agent qui exécute cette étape
    private string $skillSlug;     // Skill utilisé
    private StepInputSource $inputSource;
    private string $outputKey;     // Clé de l'output (ex: "review_report")
    private ?string $dependsOn;    // stepKey de l'étape précédente
    private ?string $condition;    // Expression conditionnelle (ex: "review_report.issues_count > 0")

    public function __construct(
        string $stepKey,
        string $name,
        string $roleId,
        string $skillSlug,
        string $outputKey,
        StepInputSource $inputSource = StepInputSource::PreviousStep,
        ?string $dependsOn = null,
        ?string $condition = null,
    ) {
        $this->id = Uuid::v7();
        $this->stepKey = $stepKey;
        $this->name = $name;
        $this->roleId = $roleId;
        $this->skillSlug = $skillSlug;
        $this->outputKey = $outputKey;
        $this->inputSource = $inputSource;
        $this->dependsOn = $dependsOn;
        $this->condition = $condition;
    }

    public function getId(): Uuid { return $this->id; }
    public function getStepKey(): string { return $this->stepKey; }
    public function getName(): string { return $this->name; }
    public function getRoleId(): string { return $this->roleId; }
    public function getSkillSlug(): string { return $this->skillSlug; }
    public function getOutputKey(): string { return $this->outputKey; }
    public function getInputSource(): StepInputSource { return $this->inputSource; }
    public function getDependsOn(): ?string { return $this->dependsOn; }
    public function getCondition(): ?string { return $this->condition; }
}
