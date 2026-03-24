<?php

declare(strict_types=1);

namespace App\Domain\Audit;

enum AuditAction: string
{
    // Projets & Modules
    case ProjectCreated  = 'project.created';
    case ProjectUpdated  = 'project.updated';
    case ModuleCreated   = 'module.created';
    case ModuleLinked    = 'module.linked_repository';

    // Équipes & Agents
    case TeamCreated     = 'team.created';
    case TeamUpdated     = 'team.updated';
    case AgentCreated    = 'agent.created';
    case AgentUpdated    = 'agent.updated';

    // Skills
    case SkillImported   = 'skill.imported';
    case SkillCreated    = 'skill.created';
    case SkillEdited     = 'skill.edited';

    // Workflows
    case WorkflowCreated  = 'workflow.created';
    case WorkflowStarted  = 'workflow.started';
    case WorkflowDryRun   = 'workflow.dry_run';
    case WorkflowCompleted = 'workflow.completed';
    case WorkflowFailed   = 'workflow.failed';
    case StepStarted      = 'workflow.step_started';
    case StepCompleted    = 'workflow.step_completed';
    case StepFailed       = 'workflow.step_failed';

    // VCS
    case VcsBranchCreated = 'vcs.branch_created';
    case VcsPrOpened      = 'vcs.pr_opened';
    case VcsPrCommented   = 'vcs.pr_commented';
}
