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
     * Wires the services required to expose execution capabilities alongside business context.
     */
    public function __construct(
        private readonly TicketTaskService $ticketTaskService,
    ) {}

    /**
     * @param TicketLog[] $ticketComments
     */
    public function buildForTicketTask(TicketTask $task, Agent $agent, string $skillSlug, array $ticketComments = []): array
    {
        $ticket = $task->getTicket();
        $context = $this->buildProjectAgentContext($ticket->getProject(), $agent);
        $executionScope = $this->ticketTaskService->describeExecutionScope($task);

        $context['interaction'] = [
            'type' => 'ticket_task_execution',
            'skill' => $skillSlug,
            'action_key' => $task->getAgentAction()->getKey(),
            'capabilities' => [
                'current_action' => [
                    'key' => $task->getAgentAction()->getKey(),
                    'label' => $task->getAgentAction()->getLabel(),
                ],
                'ticket_transitions' => $executionScope['ticket_transitions'],
                'task_actions' => $executionScope['task_actions'],
                'allowed_effects' => $executionScope['allowed_effects'],
                'must_stay_within_scope' => true,
            ],
        ];
        $context['ticket'] = [
            'id' => (string) $ticket->getId(),
            'title' => $ticket->getTitle(),
            'type' => $ticket->getType()->value,
            'priority' => $ticket->getPriority()->value,
            'status' => $ticket->getStatus()->value,
            'feature' => $ticket->getFeature()?->getName(),
            'workflow_step' => $ticket->getWorkflowStep()?->getKey(),
            'allowed_transitions' => $executionScope['ticket_transitions'],
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
            'allowed_actions' => $executionScope['task_actions'],
        ];

        if ($ticketComments !== []) {
            $pendingQuestions = array_values(array_filter(
                $ticketComments,
                static fn(TicketLog $log): bool => $log->requiresAnswer()
                    && $log->getTicketTask()?->getId()?->toRfc4122() === $task->getId()->toRfc4122(),
            ));

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

            if ($pendingQuestions !== []) {
                $context['pending_questions'] = array_map(static fn(TicketLog $log) => [
                    'id' => $log->getId()->toRfc4122(),
                    'content' => $log->getContent(),
                    'created_at' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
                ], $pendingQuestions);
            }
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
                'question_policy' => 'Ask a clarification only when it is strictly blocking for reframing or validating the request. Do not ask exploratory or comfort questions.',
                'question_batching' => 'When clarification is unavoidable, ask the smallest possible batch of blocking questions, ideally one and never more than two at once.',
                'question_reasoning' => 'Each clarification question must explicitly state the business blocker it resolves so the user can distinguish a mandatory answer from simple curiosity.',
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
                'pending_questions_rule' => 'When pending_questions is not empty, do not finalize the deliverable. Wait for the user to answer those pending questions first.',
                'no_question_stacking' => 'When pending_questions is not empty, do not add more clarification questions. Reuse the existing blockers and wait for answers.',
                'scope_rule' => 'Stay strictly within the allowed actions and ticket transitions exposed in the context. If a requested change falls outside that scope, say so explicitly instead of improvising.',
                'role_constraints' => $roleNotes,
            ],
        ];
    }
}
