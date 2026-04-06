<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Adapter\Skill;

use App\Port\SkillPort;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * SkillPort implementation for importing skills from the skills.sh marketplace.
 */
class SkillsShAdapter implements SkillPort
{
    /**
     * Initializes the adapter with the root directory containing local skills.
     */
    public function __construct(private readonly string $skillsDir) {}

    /**
     * Imports a skill from the marketplace and returns the parsed SKILL.md payload.
     */
    public function import(string $ownerAndName): array
    {
        // Runs `npx skills add owner/name` from the imported skills directory.
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

        // `npx skills` installs the skill into a dedicated subdirectory.
        $slug     = basename($ownerAndName);
        $skillDir = $importedDir . '/' . $slug;
        $mdPath   = $skillDir . '/SKILL.md';

        if (!file_exists($mdPath)) {
            throw new \RuntimeException("SKILL.md not found after import: {$mdPath}");
        }

        return $this->parseSkillMd($mdPath, $ownerAndName);
    }

    /**
     * Searches the marketplace and returns the raw CLI output for display.
     */
    public function search(string $query = ''): array
    {
        $process = new Process(
            command: $query ? ['npx', 'skills', 'find', $query] : ['npx', 'skills', 'list'],
            timeout: 30,
        );
        $process->run();

        // Returns raw CLI output for display until a structured parser is needed.
        return ['output' => $process->getOutput()];
    }

    /**
     * Parses a SKILL.md file containing YAML frontmatter followed by Markdown content.
     */
    public function parseSkillMd(string $absolutePath, string $originalSource = ''): array
    {
        $raw     = file_get_contents($absolutePath);
        $slug    = basename(dirname($absolutePath));
        $name    = $slug;
        $description = '';
        $body    = $raw;

        // Extract the optional YAML frontmatter (`--- ... ---`).
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
