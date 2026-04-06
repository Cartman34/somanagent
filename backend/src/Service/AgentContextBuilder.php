<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\ChatMessage;
use App\Entity\Project;
use App\Entity\TicketLog;
use App\Entity\TicketTask;

/**
 * Builds the context payload sent to an agent: project info, ticket, task, role, skill, and conversation history.
 */
final class AgentContextBuilder
{
    /**
     * @param TicketLog[] $ticketComments
     */
    public function buildForTicketTask(TicketTask $task, Agent $agent, string $skillSlug, array $ticketComments = []): array
    {
        $ticket = $task->getTicket();
        $context = $this->buildProjectAgentContext($ticket->getProject(), $agent);
        $context['interaction'] = [
            'type' => 'ticket_task_execution',
            'skill' => $skillSlug,
            'action_key' => $task->getAgentAction()->getKey(),
        ];
        $context['ticket'] = [
            'id' => (string) $ticket->getId(),
            'title' => $ticket->getTitle(),
            'type' => $ticket->getType()->value,
            'priority' => $ticket->getPriority()->value,
            'status' => $ticket->getStatus()->value,
            'feature' => $ticket->getFeature()?->getName(),
            'workflow_step' => $ticket->getWorkflowStep()?->getKey(),
        ];
        $context['ticket_task'] = [
            'id' => (string) $task->getId(),
            'title' => $task->getTitle(),
            'description' => $task->getDescription(),
            'priority' => $task->getPriority()->value,
            'status' => $task->getStatus()->value,
            'branch' => $task->getBranchName(),
            'parent' => $task->getParent()?->getTitle(),
            'workflow_step' => $task->getWorkflowStep()?->getKey(),
            'action' => [
                'key' => $task->getAgentAction()->getKey(),
                'label' => $task->getAgentAction()->getLabel(),
            ],
            'assigned_role' => $task->getAssignedRole()?->getName(),
            'assigned_agent' => $task->getAssignedAgent()?->getName(),
        ];

        if ($ticketComments !== []) {
            $context['ticket_conversation'] = array_map(static fn(TicketLog $log) => [
                'author' => $log->getAuthorName() ?? $log->getAuthorType() ?? 'system',
                'author_type' => $log->getAuthorType(),
                'action' => $log->getAction(),
                'requires_answer' => $log->requiresAnswer(),
                'reply_to' => $log->getReplyToLogId()?->toRfc4122(),
                'context' => $log->getMetadata()['context'] ?? null,
                'ticket_task_id' => $log->getTicketTask()?->getId()->toRfc4122(),
                'content' => $log->getContent(),
                'created_at' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $ticketComments);
        }

        return $context;
    }

    /**
     * @param ChatMessage[] $conversation
     */
    public function buildForProjectChat(Project $project, Agent $agent, array $conversation = []): array
    {
        $context = $this->buildProjectAgentContext($project, $agent);
        $context['interaction'] = [
            'type' => 'project_chat',
            'channel' => 'project_agent_sheet',
        ];

        if ($conversation !== []) {
            $context['recent_conversation'] = array_map(static fn(ChatMessage $message) => [
                'author'     => $message->getAuthor()->value,
                'is_error'   => $message->isError(),
                'content'    => $message->getContent(),
                'created_at' => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $conversation);
        }

        return $context;
    }

    private function buildProjectAgentContext(Project $project, Agent $agent): array
    {
        $roleSlug = $agent->getRole()?->getSlug();

        $roleNotes = match ($roleSlug) {
            'product-owner' => [
                'scope' => 'You are responsible for product reframing and functional scoping.',
                'constraint' => 'Do not make technical decisions on your own.',
                'allowed' => 'You may relay explicitly provided technical constraints and add functional scope such as platforms, languages, or user profiles.',
                'handoff' => 'Technical tradeoffs, technical analysis, and task breakdown belong to the Lead Tech.',
            ],
            default => [],
        };

        return [
            'identity' => [
                'agent_name'        => $agent->getName(),
                'agent_description' => $agent->getDescription(),
                'role_name'         => $agent->getRole()?->getName(),
                'role_slug'         => $agent->getRole()?->getSlug(),
                'role_description'  => $agent->getRole()?->getDescription(),
                'role_skills'       => $agent->getRole()
                    ? array_map(
                        static fn($skill) => [
                            'name' => $skill->getName(),
                            'slug' => $skill->getSlug(),
                            'description' => $skill->getDescription(),
                        ],
                        $agent->getRole()->getSkills()->toArray(),
                    )
                    : [],
            ],
            'project' => [
                'id'          => (string) $project->getId(),
                'name'        => $project->getName(),
                'description' => $project->getDescription(),
                'repository'  => $project->getRepositoryUrl(),
                'team'        => $project->getTeam()?->getName(),
                'team_description' => $project->getTeam()?->getDescription(),
                'modules'     => array_map(
                    static fn($module) => [
                        'name' => $module->getName(),
                        'description' => $module->getDescription(),
                        'stack' => $module->getStack(),
                        'status' => $module->getStatus()->value,
                        'repository' => $module->getRepositoryUrl(),
                    ],
                    $project->getModules()->toArray(),
                ),
            ],
            'operating_notes' => [
                'identity_is_known' => 'Your identity, role, and project are already provided in this context.',
                'do_not_ask_identity_again' => 'Do not ask again who you are, which role you play, or which project you are working on unless the context is explicitly contradictory.',
                'do_not_repeat_questions' => 'Do not repeat questions already present in ticket_conversation (action=agent_question). Check ticket_conversation before asking for clarification and omit questions that already appear there, whether answered or not.',
                'role_constraints' => $roleNotes,
            ],
        ];
    }
}
