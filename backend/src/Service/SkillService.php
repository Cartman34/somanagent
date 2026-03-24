<?php

declare(strict_types=1);

namespace App\Service;

use App\Adapter\Skill\SkillsShAdapter;
use App\Entity\Skill;
use App\Enum\AuditAction;
use App\Enum\SkillSource;
use App\Repository\SkillRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class SkillService
{
    private SkillsShAdapter $skillsShAdapter;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SkillRepository        $skillRepository,
        private readonly AuditService           $audit,
        private readonly string                 $skillsDir,
    ) {
        $this->skillsShAdapter = new SkillsShAdapter($skillsDir);
    }

    /**
     * Importe un skill depuis skills.sh et le sauvegarde en base + sur disque.
     */
    public function importFromRegistry(string $ownerAndName): Skill
    {
        $data = $this->skillsShAdapter->import($ownerAndName);

        // Vérifie si le slug existe déjà
        $existing = $this->skillRepository->findOneBy(['slug' => $data['slug']]);
        if ($existing !== null) {
            // Met à jour le contenu
            $existing->setContent($data['content']);
            $this->em->flush();
            $this->audit->log(AuditAction::SkillUpdated, 'Skill', (string) $existing->getId(), ['slug' => $data['slug']]);
            return $existing;
        }

        $skill = new Skill(
            slug:           $data['slug'],
            name:           $data['name'],
            content:        $data['content'],
            filePath:       $data['filePath'],
            source:         SkillSource::Imported,
            description:    $data['description'],
            originalSource: $data['originalSource'],
        );

        $this->em->persist($skill);
        $this->em->flush();
        $this->audit->log(AuditAction::SkillImported, 'Skill', (string) $skill->getId(), ['slug' => $data['slug'], 'source' => $ownerAndName]);
        return $skill;
    }

    /**
     * Crée un skill personnalisé.
     */
    public function createCustom(string $slug, string $name, string $content, ?string $description = null): Skill
    {
        $dir      = $this->skillsDir . '/custom/' . $slug;
        $filePath = 'custom/' . $slug . '/SKILL.md';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Construit le SKILL.md
        $mdContent = "---\nname: {$name}\ndescription: " . ($description ?? '') . "\n---\n\n{$content}";
        file_put_contents($this->skillsDir . '/' . $filePath, $mdContent);

        $skill = new Skill(
            slug:        $slug,
            name:        $name,
            content:     $mdContent,
            filePath:    $filePath,
            source:      SkillSource::Custom,
            description: $description,
        );

        $this->em->persist($skill);
        $this->em->flush();
        $this->audit->log(AuditAction::SkillCreated, 'Skill', (string) $skill->getId(), ['slug' => $slug]);
        return $skill;
    }

    /**
     * Met à jour le contenu d'un skill (synchronise le fichier SKILL.md).
     */
    public function updateContent(Skill $skill, string $content): Skill
    {
        $skill->setContent($content);
        $absolutePath = $this->skillsDir . '/' . $skill->getFilePath();
        file_put_contents($absolutePath, $content);

        $this->em->flush();
        $this->audit->log(AuditAction::SkillUpdated, 'Skill', (string) $skill->getId());
        return $skill;
    }

    public function delete(Skill $skill): void
    {
        $id = (string) $skill->getId();
        $this->em->remove($skill);
        $this->em->flush();
        $this->audit->log(AuditAction::SkillDeleted, 'Skill', $id);
    }

    /** @return Skill[] */
    public function findAll(): array
    {
        return $this->skillRepository->findAll();
    }

    public function findById(string $id): ?Skill
    {
        return $this->skillRepository->find(Uuid::fromString($id));
    }

    public function findBySlug(string $slug): ?Skill
    {
        return $this->skillRepository->findOneBy(['slug' => $slug]);
    }
}
