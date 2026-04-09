<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Exhaustive list of auditable actions in the application.
 *
 * Convention: values follow the pattern `<entity>.<event>` in snake_case.
 *
 * The `entityType` field on {@see \App\Entity\AuditLog} discriminates between entity classes
 * when the same action applies to multiple types. For example, `task.created` is used for
 * both `Ticket` (entityType='Ticket') and `TicketTask` (entityType='TicketTask').
 */
enum AuditAction: string
{
    // Projects
    case ProjectCreated = 'project.created';
    case ProjectUpdated = 'project.updated';
    case ProjectDeleted = 'project.deleted';

    // Modules
    case ModuleCreated = 'module.created';
    case ModuleUpdated = 'module.updated';
    case ModuleDeleted = 'module.deleted';

    // Teams
    case TeamCreated      = 'team.created';
    case TeamUpdated      = 'team.updated';
    case TeamDeleted      = 'team.deleted';
    case TeamAgentAdded   = 'team.agent.added';
    case TeamAgentRemoved = 'team.agent.removed';

    // Roles
    case RoleCreated = 'role.created';
    case RoleUpdated = 'role.updated';
    case RoleDeleted = 'role.deleted';

    // Agents
    case AgentCreated = 'agent.created';
    case AgentUpdated = 'agent.updated';
    case AgentDeleted = 'agent.deleted';

    // Skills
    case SkillImported = 'skill.imported';
    case SkillCreated  = 'skill.created';
    case SkillUpdated  = 'skill.updated';
    case SkillDeleted  = 'skill.deleted';

    // Workflows — definition only (create/update/delete of workflow configurations)
    case WorkflowCreated = 'workflow.created';
    case WorkflowUpdated = 'workflow.updated';
    case WorkflowDeleted = 'workflow.deleted';

    // Features
    case FeatureCreated = 'feature.created';
    case FeatureUpdated = 'feature.updated';
    case FeatureDeleted = 'feature.deleted';

    // Tickets and ticket tasks — entityType discriminates between Ticket and TicketTask
    case TaskCreated         = 'task.created';
    case TaskUpdated         = 'task.updated';
    case TaskDeleted         = 'task.deleted';
    case TaskStatusChanged   = 'task.status_changed';
    case TaskProgressUpdated = 'task.progress_updated';
    case TaskReprioritized   = 'task.reprioritized';

    // Chat
    case ChatMessageSent = 'chat.message_sent';
}
