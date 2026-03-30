<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Enum;

enum AuditAction: string
{
    // Projets
    case ProjectCreated = 'project.created';
    case ProjectUpdated = 'project.updated';
    case ProjectDeleted = 'project.deleted';

    // Modules
    case ModuleCreated = 'module.created';
    case ModuleUpdated = 'module.updated';
    case ModuleDeleted = 'module.deleted';

    // Équipes
    case TeamCreated      = 'team.created';
    case TeamUpdated      = 'team.updated';
    case TeamDeleted      = 'team.deleted';
    case TeamAgentAdded   = 'team.agent.added';
    case TeamAgentRemoved = 'team.agent.removed';

    // Rôles
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

    // Workflows
    case WorkflowCreated       = 'workflow.created';
    case WorkflowUpdated       = 'workflow.updated';
    case WorkflowDeleted       = 'workflow.deleted';
    case WorkflowRun           = 'workflow.run';
    case WorkflowDryRun        = 'workflow.dry_run';
    case WorkflowStepCompleted = 'workflow.step.completed';
    case WorkflowStepFailed    = 'workflow.step.failed';
    case WorkflowCompleted     = 'workflow.completed';
    case WorkflowFailed        = 'workflow.failed';
    case WorkflowCancelled     = 'workflow.cancelled';

    // Features
    case FeatureCreated = 'feature.created';
    case FeatureUpdated = 'feature.updated';
    case FeatureDeleted = 'feature.deleted';

    // Tâches
    case TaskCreated          = 'task.created';
    case TaskUpdated          = 'task.updated';
    case TaskDeleted          = 'task.deleted';
    case TaskAssigned         = 'task.assigned';
    case TaskStatusChanged    = 'task.status_changed';
    case TaskProgressUpdated  = 'task.progress_updated';
    case TaskValidationAsked  = 'task.validation_asked';
    case TaskValidated        = 'task.validated';
    case TaskRejected         = 'task.rejected';
    case TaskReprioritized    = 'task.reprioritized';

    // Chat
    case ChatMessageSent = 'chat.message_sent';
}
