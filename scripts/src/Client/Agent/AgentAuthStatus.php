<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Client\Agent;

/**
 * Structured snapshot of an agent's authentication state, collected by an auth manager.
 *
 * Pure value object: it carries STRUCTURED data only — never any formatting. Path states keep raw
 * type/size/mtime, comparisons keep booleans, and captured probe outputs keep exit code + text.
 * Rendering the snapshot for the terminal is the runner's responsibility.
 *
 * The all-default constructor keeps the object autowiring-safe; callers populate it through the
 * mutator helpers as they collect the state.
 *
 * @api Populated by {@see AbstractAgentAuthManager} and rendered by the auth runners.
 */
final class AgentAuthStatus
{
    public const TYPE_DIR = 'dir';
    public const TYPE_FILE = 'file';
    public const TYPE_MISSING = 'missing';

    /**
     * Ordered groups of probed path states.
     *
     * Each group is a labelled step (e.g. "Checking WSL Claude auth files") carrying the path
     * states observed during that step.
     *
     * @var list<array{label: string, paths: list<array{path: string, type: string, size: int, mtime: int}>}>
     */
    private array $pathGroups = [];

    /**
     * Sync comparison results between the WSL source and the Docker shared copy.
     *
     * @var list<array{label: string, inSync: bool}>
     */
    private array $comparisons = [];

    /**
     * Free-form structured notes surfaced to the user (e.g. the OpenCode provider-credentials note).
     *
     * @var list<string>
     */
    private array $notes = [];

    /**
     * Captured outputs of the auth-status probe commands.
     *
     * @var list<array{label: string, exitCode: int, output: string}>
     */
    private array $commandOutputs = [];

    /**
     * Records a labelled group of probed path states.
     *
     * @param list<array{path: string, type: string, size: int, mtime: int}> $paths
     */
    public function addPathGroup(string $label, array $paths): void
    {
        $this->pathGroups[] = ['label' => $label, 'paths' => $paths];
    }

    /**
     * Records a sync comparison outcome between WSL and the Docker shared copy.
     */
    public function addComparison(string $label, bool $inSync): void
    {
        $this->comparisons[] = ['label' => $label, 'inSync' => $inSync];
    }

    /**
     * Records a structured note to surface to the user.
     */
    public function addNote(string $note): void
    {
        $this->notes[] = $note;
    }

    /**
     * Records the captured output of an auth-status probe command.
     */
    public function addCommandOutput(string $label, int $exitCode, string $output): void
    {
        $this->commandOutputs[] = ['label' => $label, 'exitCode' => $exitCode, 'output' => $output];
    }

    /**
     * @return list<array{label: string, paths: list<array{path: string, type: string, size: int, mtime: int}>}>
     */
    public function getPathGroups(): array
    {
        return $this->pathGroups;
    }

    /**
     * @return list<array{label: string, inSync: bool}>
     */
    public function getComparisons(): array
    {
        return $this->comparisons;
    }

    /**
     * @return list<string>
     */
    public function getNotes(): array
    {
        return $this->notes;
    }

    /**
     * @return list<array{label: string, exitCode: int, output: string}>
     */
    public function getCommandOutputs(): array
    {
        return $this->commandOutputs;
    }
}
