<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

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
        return rtrim($this->formatContextValue($this->context));
    }

    private function formatContextValue(mixed $value, int $depth = 0, ?string $label = null): string
    {
        $indent = str_repeat('  ', $depth);

        if (!is_array($value)) {
            $prefix = $label !== null ? "**{$label}** : " : '';
            return $indent . $prefix . (string) $value . "\n";
        }

        $lines = [];
        foreach ($value as $key => $item) {
            $itemLabel = is_string($key) ? $key : null;
            if (is_array($item)) {
                if ($itemLabel !== null) {
                    $lines[] = $indent . "- **{$itemLabel}**";
                } else {
                    $lines[] = $indent . '-';
                }
                $lines[] = rtrim($this->formatContextValue($item, $depth + 1));
                continue;
            }

            if ($itemLabel !== null) {
                $lines[] = $indent . "- **{$itemLabel}** : " . (string) $item;
            } else {
                $lines[] = $indent . '- ' . (string) $item;
            }
        }

        return implode("\n", array_filter($lines)) . "\n";
    }
}
