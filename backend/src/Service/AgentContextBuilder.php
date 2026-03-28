<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\ChatMessage;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TaskLog;

final class AgentContextBuilder
{
    /**
     * @param TaskLog[] $ticketComments
     */
    public function buildForTask(Task $task, Agent $agent, string $skillSlug, array $ticketComments = []): array
    {
        $context = $this->buildProjectAgentContext($task->getProject(), $agent);
        $context['interaction'] = [
            'type' => 'task_execution',
            'skill' => $skillSlug,
        ];
        $context['task'] = [
            'id'       => (string) $task->getId(),
            'title'    => $task->getTitle(),
            'type'     => $task->getType()->value,
            'priority' => $task->getPriority()->value,
            'status'   => $task->getStatus()->value,
            'story'    => $task->getStoryStatus()?->value,
            'branch'   => $task->getBranchName(),
            'parent'   => $task->getParent()?->getTitle(),
            'feature'  => $task->getFeature()?->getName(),
            'assigned_role' => $task->getAssignedRole()?->getName(),
            'assigned_agent' => $task->getAssignedAgent()?->getName(),
        ];

        if ($ticketComments !== []) {
            $context['ticket_conversation'] = array_map(static fn(TaskLog $log) => [
                'author'          => $log->getAuthorName() ?? $log->getAuthorType() ?? 'system',
                'author_type'     => $log->getAuthorType(),
                'action'          => $log->getAction(),
                'requires_answer' => $log->requiresAnswer(),
                'reply_to'        => $log->getReplyToLogId()?->toRfc4122(),
                'context'         => $log->getMetadata()['context'] ?? null,
                'content'         => $log->getContent(),
                'created_at'      => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
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
                'identity_is_known' => 'Tu connais déjà ton identité, ton rôle et le projet grâce à ce contexte.',
                'do_not_ask_identity_again' => 'Ne redemande pas qui tu es, quel rôle tu joues ou sur quel projet tu travailles sauf si le contexte est explicitement contradictoire.',
            ],
        ];
    }
}
