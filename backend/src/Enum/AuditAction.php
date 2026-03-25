<?php

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
    case TeamCreated = 'team.created';
    case TeamUpdated = 'team.updated';
    case TeamDeleted = 'team.deleted';
    case RoleAdded   = 'team.role.added';
    case RoleRemoved = 'team.role.removed';

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
}
