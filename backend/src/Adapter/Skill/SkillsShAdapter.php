<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Adapter\Skill;

use App\Port\SkillPort;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class SkillsShAdapter implements SkillPort
{
    public function __construct(private readonly string $skillsDir) {}

    public function import(string $ownerAndName): array
    {
        // Lance : npx skills add owner/name dans le dossier skills/imported/
        $importedDir = $this->skillsDir . '/imported';

        $process = new Process(
            command: ['npx', 'skills', 'add', $ownerAndName],
            cwd:     $importedDir,
            timeout: 60,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        // Le skill est installé dans un sous-dossier par npx skills
        $slug     = basename($ownerAndName);
        $skillDir = $importedDir . '/' . $slug;
        $mdPath   = $skillDir . '/SKILL.md';

        if (!file_exists($mdPath)) {
            throw new \RuntimeException("SKILL.md introuvable après import : {$mdPath}");
        }

        return $this->parseSkillMd($mdPath, $ownerAndName);
    }

    public function search(string $query = ''): array
    {
        $process = new Process(
            command: $query ? ['npx', 'skills', 'find', $query] : ['npx', 'skills', 'list'],
            timeout: 30,
        );
        $process->run();

        // Retourne la sortie brute pour affichage — à parser selon le format de sortie de skills CLI
        return ['output' => $process->getOutput()];
    }

    /**
     * Parse un fichier SKILL.md (frontmatter YAML + corps Markdown).
     */
    public function parseSkillMd(string $absolutePath, string $originalSource = ''): array
    {
        $raw     = file_get_contents($absolutePath);
        $slug    = basename(dirname($absolutePath));
        $name    = $slug;
        $description = '';
        $body    = $raw;

        // Extraction du frontmatter YAML (--- ... ---)
        if (str_starts_with($raw, '---')) {
            $end = strpos($raw, '---', 3);
            if ($end !== false) {
                $frontmatter = substr($raw, 3, $end - 3);
                $body        = ltrim(substr($raw, $end + 3));

                foreach (explode("\n", $frontmatter) as $line) {
                    if (str_starts_with($line, 'name:')) {
                        $name = trim(substr($line, 5));
                    } elseif (str_starts_with($line, 'description:')) {
                        $description = trim(substr($line, 12));
                    }
                }
            }
        }

        $relPath = 'imported/' . $slug . '/SKILL.md';

        return [
            'slug'           => $slug,
            'name'           => $name,
            'description'    => $description,
            'content'        => $raw,
            'filePath'       => $relPath,
            'originalSource' => $originalSource,
        ];
    }
}
