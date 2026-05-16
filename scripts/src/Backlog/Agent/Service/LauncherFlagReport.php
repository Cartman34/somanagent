<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Service;

/**
 * Per-launcher validation report produced by LauncherFlagValidator.
 *
 * `$skipped` is true when the binary is not installed locally; in that case
 * `$checks` is empty and the report contributes neither success nor failure.
 * When the binary is available, `$checks` lists one LauncherFlagCheck per
 * declared required flag.
 */
final class LauncherFlagReport
{
    /**
     * @param string $client The AgentClient value (claude, codex, opencode, gemini)
     * @param bool $skipped True when the binary is not installed and no checks were run
     * @param list<LauncherFlagCheck> $checks One check per required flag (empty when skipped)
     */
    public function __construct(
        public readonly string $client,
        public readonly bool $skipped,
        public readonly array $checks,
    ) {}

    /**
     * Returns true when at least one declared flag is missing from the binary help.
     */
    public function hasMissingFlag(): bool
    {
        foreach ($this->checks as $check) {
            if (!$check->present) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function missingFlags(): array
    {
        $missing = [];
        foreach ($this->checks as $check) {
            if (!$check->present) {
                $missing[] = $check->flag;
            }
        }

        return $missing;
    }
}
