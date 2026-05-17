<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Service;

use SoManAgent\Script\Console;

/**
 * Resolves --body-file paths for backlog commands running inside a worktree-proxied subprocess.
 *
 * When a relative path is given alongside an entry reference, the resolver derives the
 * developer worktree (WA) from the entry's assigned agent (meta.agent) and tries that
 * directory first. This lets reviewers and developers write body files in their natural
 * working directory (the WA) without knowing the WP path.
 *
 * Resolution order for relative paths with an entry reference:
 *   1. `<wa>/<path>` when the entry has an assigned agent and the WA exists on disk.
 *   2. `<cwd>/<path>` (WP cwd in the proxied subprocess).
 *   Both found (collision): WA wins; a warning is emitted via Console.
 *   Neither found: a RuntimeException listing all tested paths.
 *
 * For commands without an entry reference (`resolve`), only `<cwd>/<path>` is tested.
 * Absolute paths bypass all of the above and are validated as-is.
 */
final class BodyFilePathResolver
{
    private BacklogBoardService $boardService;

    private BacklogWorktreeService $worktreeService;

    private Console $console;

    private string $boardPath;

    /**
     * @param BacklogBoardService $boardService
     * @param BacklogWorktreeService $worktreeService
     * @param Console $console
     * @param string $boardPath
     */
    public function __construct(
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService,
        Console $console,
        string $boardPath
    ) {
        $this->boardService = $boardService;
        $this->worktreeService = $worktreeService;
        $this->console = $console;
        $this->boardPath = $boardPath;
    }

    /**
     * Resolves a --body-file path using the assigned agent worktree derived from the entry meta.
     *
     * For absolute paths: validates existence and returns as-is.
     * For relative paths: tries the WA (derived from entry meta.agent) first, then cwd.
     * If the file is present in both, the WA path wins and a warning is emitted.
     * If the file is found nowhere, throws with the list of tested paths.
     *
     * @param string $path Raw --body-file value from the CLI
     * @param string $entryRef Stable entry reference (feature slug or feature/task)
     * @return string Resolved absolute path to the body file
     * @throws \RuntimeException when the file cannot be found
     */
    public function resolveForEntry(string $path, string $entryRef): string
    {
        if (str_starts_with($path, '/')) {
            if (is_file($path)) {
                return $path;
            }
            throw new \RuntimeException(sprintf('--body-file path does not exist: %s', $path));
        }

        $waCandidatePath = null;
        $agentCode = $this->tryGetAgentCodeFromEntry($entryRef);
        if ($agentCode !== null) {
            $wa = $this->worktreeService->getAgentWorktreePath($agentCode);
            if (is_dir($wa)) {
                $waCandidatePath = $wa . '/' . $path;
            }
        }

        $cwdCandidatePath = getcwd() . '/' . $path;
        $waExists = $waCandidatePath !== null && is_file($waCandidatePath);
        $cwdExists = is_file($cwdCandidatePath);

        if ($waExists && $cwdExists) {
            $this->console->warn(sprintf(
                "--body-file '%s' found in both WA and cwd; using WA path '%s'.",
                $path,
                $waCandidatePath,
            ));

            return (string) $waCandidatePath;
        }

        if ($waExists) {
            return (string) $waCandidatePath;
        }

        if ($cwdExists) {
            return $cwdCandidatePath;
        }

        $tested = $waCandidatePath !== null
            ? [$waCandidatePath, $cwdCandidatePath]
            : [$cwdCandidatePath];

        throw new \RuntimeException(sprintf(
            '--body-file not found. Tested: %s',
            implode(', ', $tested),
        ));
    }

    /**
     * Resolves a --body-file path without an entry reference (no WA lookup).
     *
     * For absolute paths: validates existence and returns as-is.
     * For relative paths: resolves against cwd only.
     *
     * @param string $path Raw --body-file value from the CLI
     * @return string Resolved absolute path to the body file
     * @throws \RuntimeException when the file cannot be found
     */
    public function resolve(string $path): string
    {
        if (str_starts_with($path, '/')) {
            if (is_file($path)) {
                return $path;
            }
            throw new \RuntimeException(sprintf('--body-file path does not exist: %s', $path));
        }

        $candidate = getcwd() . '/' . $path;
        if (!is_file($candidate)) {
            throw new \RuntimeException(sprintf('--body-file path does not exist: %s', $candidate));
        }

        return $candidate;
    }

    /**
     * Reads the assigned agent code from the board entry identified by the reference.
     * Returns null when the entry cannot be found, is unassigned, or any read error occurs.
     */
    private function tryGetAgentCodeFromEntry(string $entryRef): ?string
    {
        try {
            $board = $this->boardService->loadBoard($this->boardPath);
            if (str_contains($entryRef, '/')) {
                $match = $this->boardService->resolveTaskByReference($board, $entryRef, 'body-file');
            } else {
                $slug = $this->boardService->normalizeFeatureSlug($entryRef);
                $match = $this->boardService->findParentFeatureEntry($board, $slug);
                if ($match === null) {
                    return null;
                }
            }

            return $match->getEntry()->getAgent();
        } catch (\Throwable) {
            return null;
        }
    }
}
