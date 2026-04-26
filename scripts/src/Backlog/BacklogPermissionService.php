<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

/**
 * Handles role-based permissions and workflow restrictions.
 */
final class BacklogPermissionService
{
    public const ROLE_MANAGER = 'manager';
    public const ROLE_DEVELOPER = 'developer';

    private const ENV_ACTIVE_ROLE = 'SOMANAGER_ROLE';
    private const ENV_ACTIVE_AGENT = 'SOMANAGER_AGENT';

    public function requireWorkflowRole(): string
    {
        $role = strtolower(trim((string) getenv(self::ENV_ACTIVE_ROLE)));
        if (!in_array($role, [self::ROLE_MANAGER, self::ROLE_DEVELOPER], true)) {
            throw new \RuntimeException(sprintf(
                'Assignment commands require %s=manager or %s=developer.',
                self::ENV_ACTIVE_ROLE,
                self::ENV_ACTIVE_ROLE,
            ));
        }

        return $role;
    }

    public function requireWorkflowAgent(): string
    {
        $agent = trim((string) getenv(self::ENV_ACTIVE_AGENT));
        if ($agent === '') {
            throw new \RuntimeException(sprintf(
                'Developer assignment commands require %s=<code>.',
                self::ENV_ACTIVE_AGENT,
            ));
        }

        return $agent;
    }

    public function assertCanAssignFeature(
        string $actorRole,
        ?string $actorAgent,
        string $targetAgent,
        string $feature,
        BacklogBoard $board,
        BacklogEntryResolver $entryResolver
    ): void {
        if ($actorRole === self::ROLE_MANAGER) {
            return;
        }

        if ($actorAgent !== $targetAgent) {
            throw new \RuntimeException(sprintf(
                'Developer role can only assign itself. %s must match --agent.',
                self::ENV_ACTIVE_AGENT,
            ));
        }

        $match = $entryResolver->requireFeature($board, $feature);
        $assignedAgent = $match->getEntry()->getAgent();
        if ($assignedAgent !== null && $assignedAgent !== $actorAgent) {
            throw new \RuntimeException(sprintf(
                'Feature %s is already assigned to %s. Only manager can reassign it.',
                $feature,
                $assignedAgent,
            ));
        }
    }

    public function assertCanUnassignFeature(
        string $actorRole,
        ?string $actorAgent,
        string $targetAgent,
        string $feature,
        BoardEntry $entry
    ): void {
        if ($actorRole === self::ROLE_MANAGER) {
            return;
        }

        if ($actorAgent !== $targetAgent) {
            throw new \RuntimeException(sprintf(
                'Developer role can only unassign itself. %s must match --agent.',
                self::ENV_ACTIVE_AGENT,
            ));
        }

        $assignedAgent = $entry->getAgent();
        if ($assignedAgent !== $actorAgent) {
            throw new \RuntimeException(sprintf(
                'Feature %s is assigned to %s. Developer role can only unassign its own feature.',
                $feature,
                $assignedAgent === null ? 'no agent' : $assignedAgent,
            ));
        }
    }
}
