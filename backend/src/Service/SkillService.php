<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Adapter\Skill\SkillsShAdapter;
use App\Entity\Skill;
use App\Enum\AuditAction;
use App\Enum\SkillSource;
use App\Repository\SkillRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Manages skills: CRUD, import from skills.sh marketplace, and custom skill creation.
 */
class SkillService
{
    private SkillsShAdapter $skillsShAdapter;

    /**
     * Initializes the service with its dependencies and builds the skills registry adapter.
     */
    public function __construct(
        private readonly EntityService   $entityService,
        private readonly SkillRepository $skillRepository,
        private readonly string          $skillsDir,
    ) {
        $this->skillsShAdapter = new SkillsShAdapter($skillsDir);
    }

    /**
     * Importe un skill depuis skills.sh et le sauvegarde en base + sur disque.
     */
    public function importFromRegistry(string $ownerAndName): Skill
    {
        $data = $this->skillsShAdapter->import($ownerAndName);

        $existing = $this->skillRepository->findOneBy(['slug' => $data['slug']]);
        if ($existing !== null) {
            $existing->setContent($data['content']);
            $this->entityService->update($existing, AuditAction::SkillUpdated, ['slug' => $data['slug']]);
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

        $this->entityService->create($skill, AuditAction::SkillImported, [
            'slug'   => $data['slug'],
            'source' => $ownerAndName,
        ]);

        return $skill;
    }

    /**
     * Creates a custom skill and writes its SKILL.md file on disk.
     */
    public function createCustom(string $slug, string $name, string $content, ?string $description = null): Skill
    {
        $dir      = $this->skillsDir . '/custom/' . $slug;
        $filePath = 'custom/' . $slug . '/SKILL.md';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

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

        $this->entityService->create($skill, AuditAction::SkillCreated, ['slug' => $slug]);

        return $skill;
    }

    /**
     * Updates the skill content and syncs the SKILL.md file on disk.
     */
    public function updateContent(Skill $skill, string $content): Skill
    {
        $skill->setContent($content);
        file_put_contents($this->skillsDir . '/' . $skill->getFilePath(), $content);
        $this->entityService->update($skill, AuditAction::SkillUpdated);

        return $skill;
    }

    /**
     * Deletes a skill and records the audit event.
     */
    public function delete(Skill $skill): void
    {
        $this->entityService->delete($skill, AuditAction::SkillDeleted);
    }

    /**
     * @return Skill[]
     */
    public function findAll(): array
    {
        return $this->skillRepository->findAll();
    }

    /**
     * Finds a skill by its UUID string identifier.
     */
    public function findById(string $id): ?Skill
    {
        return $this->skillRepository->find(Uuid::fromString($id));
    }

    /**
     * Finds a skill by its unique slug.
     */
    public function findBySlug(string $slug): ?Skill
    {
        return $this->skillRepository->findOneBy(['slug' => $slug]);
    }
}
