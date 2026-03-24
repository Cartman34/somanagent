<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Prompt final envoyé à un agent.
 * Construit en assemblant le contenu du skill + le contexte de la tâche.
 */
final class Prompt
{
    private function __construct(
        private readonly string $skillContent,
        private readonly string $taskInstruction,
        private readonly array  $context = [],
    ) {}

    public static function create(
        string $skillContent,
        string $taskInstruction,
        array  $context = [],
    ): self {
        return new self($skillContent, $taskInstruction, $context);
    }

    /**
     * Construit le texte final du prompt.
     */
    public function build(): string
    {
        $parts = [];

        if ($this->skillContent !== '') {
            $parts[] = "# Instructions du skill\n\n" . $this->skillContent;
        }

        if (!empty($this->context)) {
            $parts[] = "# Contexte\n\n" . $this->formatContext();
        }

        $parts[] = "# Tâche\n\n" . $this->taskInstruction;

        return implode("\n\n---\n\n", $parts);
    }

    public function getSkillContent(): string    { return $this->skillContent; }
    public function getTaskInstruction(): string { return $this->taskInstruction; }
    public function getContext(): array          { return $this->context; }

    private function formatContext(): string
    {
        $lines = [];
        foreach ($this->context as $key => $value) {
            $lines[] = "**{$key}** : " . (is_array($value) ? json_encode($value) : $value);
        }
        return implode("\n", $lines);
    }
}
