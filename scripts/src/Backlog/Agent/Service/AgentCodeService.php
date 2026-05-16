<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Service;

use SoManAgent\Script\Backlog\Agent\Client\ProcessSignaler;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Exception\ActiveSessionException;
use SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Model\BacklogBoard;

/**
 * Allocates and validates agent codes (dXX / rXX / mXX).
 *
 * Allocation scans managed worktrees, active backlog entries, and agent-sessions.json
 * to find the lowest unused two-digit number >= 10 for the requested role.
 * Numbers 01-09 are reserved for explicit operator allocation via --code=<code>
 * and are never produced by auto-allocation.
 */
final class AgentCodeService
{
    private string $worktreesRoot;
    private string $projectRoot;
    private BacklogBoardService $boardService;
    private AgentSessionService $sessionService;
    private string $boardPath;
    private ProcessSignaler $signaler;

    /**
     * @param string $projectRoot Absolute path to the main workspace
     * @param string $worktreesRoot Absolute path to the worktrees directory
     * @param string $boardPath Absolute path to the backlog board file
     * @param BacklogBoardService $boardService
     * @param AgentSessionService $sessionService
     * @param ProcessSignaler $signaler Used to check process aliveness when raising ActiveSessionException
     */
    public function __construct(
        string $projectRoot,
        string $worktreesRoot,
        string $boardPath,
        BacklogBoardService $boardService,
        AgentSessionService $sessionService,
        ProcessSignaler $signaler,
    ) {
        $this->projectRoot = $projectRoot;
        $this->worktreesRoot = $worktreesRoot;
        $this->boardPath = $boardPath;
        $this->boardService = $boardService;
        $this->sessionService = $sessionService;
        $this->signaler = $signaler;
    }

    /**
     * Allocates the lowest free code >= 10 for the given role.
     *
     * Numbers 01-09 are reserved for explicit operator allocation and are never produced here.
     */
    public function allocateForRole(AgentRole $role): string
    {
        $used = $this->collectUsedNumbers($role);

        for ($n = 10; $n <= 99; $n++) {
            if (!in_array($n, $used, true)) {
                return sprintf('%s%02d', $role->codePrefix(), $n);
            }
        }

        throw new \RuntimeException(sprintf('No free code available for role %s.', $role->value));
    }

    /**
     * Validates that a given code is well-formed and matches the expected role.
     *
     * @throws \RuntimeException on format mismatch or role mismatch
     * @throws ActiveSessionException when the code has a live session
     */
    public function validate(string $code, AgentRole $role, ?BacklogBoard $board = null, ?string $currentLabel = null): void
    {
        if (!preg_match('/^[drm]\d{2,}$/', $code)) {
            throw new \RuntimeException(sprintf(
                "Invalid agent code format '%s'. Expected format: <prefix><nn> (e.g. d10, r10, m10).",
                $code,
            ));
        }

        $prefix = $code[0];
        if ($prefix !== $role->codePrefix()) {
            throw new \RuntimeException(sprintf(
                "Code '%s' does not match role '%s' (expected prefix '%s', got '%s').",
                $code,
                $role->value,
                $role->codePrefix(),
                $prefix,
            ));
        }

        $session = $this->sessionService->get($code);
        if ($session !== null) {
            $label = $currentLabel ?? $this->deriveCurrentLabel($code, $board);
            throw new ActiveSessionException($session, $this->projectRoot, $this->signaler, $label);
        }
    }

    /**
     * @return list<int>
     */
    private function collectUsedNumbers(AgentRole $role): array
    {
        $prefix = $role->codePrefix();
        $used = [];

        // Scan managed worktrees
        if (is_dir($this->worktreesRoot)) {
            $entries = scandir($this->worktreesRoot) ?: [];
            foreach ($entries as $entry) {
                if (preg_match('/^' . preg_quote($prefix, '/') . '(\d{2,})$/', $entry, $m)) {
                    $used[] = (int) $m[1];
                }
            }
        }

        // Scan active backlog entries
        if (is_file($this->boardPath)) {
            try {
                $board = $this->boardService->loadBoard($this->boardPath);
                foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
                    $agent = $entry->getAgent() ?? '';
                    if (preg_match('/^' . preg_quote($prefix, '/') . '(\d{2,})$/', $agent, $m)) {
                        $used[] = (int) $m[1];
                    }
                }
            } catch (\RuntimeException) {
                // board unreadable: skip
            }
        }

        // Scan sessions.json
        foreach ($this->sessionService->load() as $code => $session) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '(\d{2,})$/', $code, $m)) {
                $used[] = (int) $m[1];
            }
        }

        return array_values(array_unique($used));
    }

    private function deriveCurrentLabel(string $code, ?BacklogBoard $board): ?string
    {
        if ($board === null) {
            return null;
        }

        $entries = $this->boardService->findActiveEntriesByAgent($board, $code);
        if ($entries === []) {
            return null;
        }

        $entry = $entries[0]->getEntry();
        $feature = $entry->getFeature() ?? '';
        $task = $entry->getTask() ?? '';

        return $task !== '' ? "{$feature}/{$task}" : $feature;
    }
}
