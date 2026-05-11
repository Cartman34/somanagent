<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Service;

use RuntimeException;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;

/**
 * Handles role-based permissions and workflow restrictions.
 */
final class BacklogPermissionService
{
    public const ROLE_MANAGER = 'manager';
    public const ROLE_DEVELOPER = 'developer';

    private const ENV_ACTIVE_ROLE = 'SOMANAGER_ROLE';
    private const ENV_ACTIVE_AGENT = 'SOMANAGER_AGENT';

    /**
     * Reads and validates the active workflow role from the SOMANAGER_ROLE environment variable.
     *
     * @return string The normalized role (`manager` or `developer`)
     */
    public function requireWorkflowRole(): string
    {
        $role = strtolower(trim((string) getenv(self::ENV_ACTIVE_ROLE)));
        if (!in_array($role, [self::ROLE_MANAGER, self::ROLE_DEVELOPER], true)) {
            throw new RuntimeException(sprintf(
                'Assignment commands require %s=manager or %s=developer.',
                self::ENV_ACTIVE_ROLE,
                self::ENV_ACTIVE_ROLE,
            ));
        }

        return $role;
    }

    /**
     * Reads and validates the active workflow agent code from the SOMANAGER_AGENT environment variable.
     *
     * @return string The agent code
     */
    public function requireWorkflowAgent(): string
    {
        $agent = trim((string) getenv(self::ENV_ACTIVE_AGENT));
        if ($agent === '') {
            throw new RuntimeException(sprintf(
                'Developer assignment commands require %s=<code>.',
                self::ENV_ACTIVE_AGENT,
            ));
        }

        return $agent;
    }

    /**
     * Authorizes assignment of a feature to an agent.
     *
     * Manager bypasses ownership checks. Developer is restricted to self-assignment of an
     * unassigned feature; the --agent argument must match the developer agent code from
     * SOMANAGER_AGENT.
     *
     * @param string $actorRole Caller role (manager or developer)
     * @param ?string $actorAgent Caller agent code when actorRole is developer
     * @param string $targetAgent Agent code passed via --agent
     * @param string $feature Feature slug to assign
     * @param BacklogBoard $board Loaded backlog board
     * @param BacklogBoardService $boardService Service used to resolve the feature entry
     */
    public function assertCanAssignFeature(
        string $actorRole,
        ?string $actorAgent,
        string $targetAgent,
        string $feature,
        BacklogBoard $board,
        BacklogBoardService $boardService
    ): void {
        if ($actorRole === self::ROLE_MANAGER) {
            return;
        }

        if ($actorAgent !== $targetAgent) {
            throw new RuntimeException(sprintf(
                'Developer role can only assign itself. %s must match --agent.',
                self::ENV_ACTIVE_AGENT,
            ));
        }

        $match = $boardService->resolveFeature($board, $feature);
        $assignedAgent = $match->getEntry()->getAgent();
        if ($assignedAgent !== null && $assignedAgent !== $actorAgent) {
            throw new RuntimeException(sprintf(
                'Feature %s is already assigned to %s. Only manager can reassign it.',
                $feature,
                $assignedAgent,
            ));
        }
    }

    /**
     * Authorizes unassignment of a backlog entry (feature or task).
     *
     * Manager bypasses ownership checks. Developer is restricted to its own active entries
     * and the --agent argument must match the developer agent code from SOMANAGER_AGENT.
     * For manager callers, --agent identifies the caller and does not restrict which
     * resolved entry assignment may be removed.
     *
     * @param string $actorRole Caller role (manager or developer)
     * @param ?string $actorAgent Caller agent code when actorRole is developer
     * @param string $callerAgent Agent code passed via --agent
     * @param string $entryRef Human-readable entry reference for error messages
     * @param BoardEntry $entry Resolved backlog entry to unassign
     */
    public function assertCanUnassignEntry(
        string $actorRole,
        ?string $actorAgent,
        string $callerAgent,
        string $entryRef,
        BoardEntry $entry
    ): void {
        if ($actorRole === self::ROLE_MANAGER) {
            return;
        }

        if ($actorAgent !== $callerAgent) {
            throw new RuntimeException(sprintf(
                'Developer role can only unassign itself. %s must match --agent.',
                self::ENV_ACTIVE_AGENT,
            ));
        }

        $assignedAgent = $entry->getAgent();
        if ($assignedAgent !== $actorAgent) {
            throw new RuntimeException(sprintf(
                'Entry %s is assigned to %s. Developer role can only unassign its own entry.',
                $entryRef,
                $assignedAgent === null ? 'no agent' : $assignedAgent,
            ));
        }
    }
}
