<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\TaskPriority;
use App\ValueObject\PlanningOutput;
use App\ValueObject\PlanningTask;

/**
 * Parse la sortie JSON structurée du skill tech-planning.
 *
 * Format attendu :
 * ```json
 * {
 *   "branch": "feature/us-xxx-slug",
 *   "needsDesign": false,
 *   "tasks": [
 *     { "title": "...", "description": "...", "role": "php-dev", "priority": "high", "dependsOn": [] },
 *     { "title": "...", "description": "...", "role": "frontend-dev", "priority": "medium", "dependsOn": [0] }
 *   ],
 *   "specUpdates": [
 *     { "file": "doc/technical/api.md", "note": "..." }
 *   ]
 * }
 * ```
 */
final class PlanningOutputParser
{
    /**
     * @throws \InvalidArgumentException si le JSON est invalide ou manque de champs requis
     */
    public function parse(string $rawContent): PlanningOutput
    {
        $json = $this->extractJsonBlock($rawContent);
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \InvalidArgumentException('Planning output JSON must be an object.');
        }

        $branch      = $this->requireString($data, 'branch');
        $needsDesign = (bool) ($data['needsDesign'] ?? false);
        $specUpdates = $this->parseSpecUpdates($data['specUpdates'] ?? []);
        $tasks       = $this->parseTasks($data['tasks'] ?? []);

        return new PlanningOutput($branch, $needsDesign, $tasks, $specUpdates);
    }

    /**
     * Extrait le bloc JSON entre ```json ... ``` ou retourne le contenu brut.
     */
    private function extractJsonBlock(string $content): string
    {
        if (preg_match('/```json\s*([\s\S]*?)\s*```/i', $content, $matches)) {
            return trim($matches[1]);
        }

        // Fallback: try to find raw JSON object
        if (preg_match('/(\{[\s\S]*\})/s', $content, $matches)) {
            return trim($matches[1]);
        }

        throw new \InvalidArgumentException(
            'No JSON block found in planning output. Expected ```json ... ``` block.'
        );
    }

    /**
     * @param array<mixed> $rawTasks
     * @return PlanningTask[]
     */
    private function parseTasks(array $rawTasks): array
    {
        if (empty($rawTasks)) {
            throw new \InvalidArgumentException('Planning output must contain at least one task.');
        }

        $tasks = [];
        foreach ($rawTasks as $i => $raw) {
            if (!is_array($raw)) {
                throw new \InvalidArgumentException("Task at index {$i} must be an object.");
            }

            $title       = $this->requireString($raw, 'title', "task[{$i}]");
            $description = $this->requireString($raw, 'description', "task[{$i}]");
            $role        = $this->requireString($raw, 'role', "task[{$i}]");
            $priority    = TaskPriority::tryFrom($raw['priority'] ?? '') ?? TaskPriority::Medium;
            $dependsOn   = array_map('intval', (array) ($raw['dependsOn'] ?? []));

            // Validate dependency indices
            foreach ($dependsOn as $dep) {
                if ($dep < 0 || $dep >= $i) {
                    throw new \InvalidArgumentException(
                        "Task[{$i}].dependsOn[{$dep}] is invalid: must reference a previous task index."
                    );
                }
            }

            $tasks[] = new PlanningTask($title, $description, $role, $priority, $dependsOn);
        }

        return $tasks;
    }

    /**
     * @param array<mixed> $rawUpdates
     * @return array<array{file: string, note: string}>
     */
    private function parseSpecUpdates(array $rawUpdates): array
    {
        $updates = [];
        foreach ($rawUpdates as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $updates[] = [
                'file' => (string) ($raw['file'] ?? ''),
                'note' => (string) ($raw['note'] ?? ''),
            ];
        }
        return $updates;
    }

    private function requireString(array $data, string $key, string $context = 'root'): string
    {
        if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
            throw new \InvalidArgumentException(
                "Missing or empty required field \"{$key}\" in {$context}."
            );
        }
        return $data[$key];
    }
}
