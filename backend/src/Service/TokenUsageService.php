<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TokenUsage;
use App\Entity\WorkflowStep;
use App\Repository\AgentRepository;
use App\Repository\TokenUsageRepository;
use Doctrine\ORM\EntityManagerInterface;

class TokenUsageService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TokenUsageRepository   $tokenUsageRepository,
        private readonly AgentRepository        $agentRepository,
    ) {}

    public function record(
        ?Agent        $agent,
        string        $model,
        int           $inputTokens,
        int           $outputTokens,
        ?int          $durationMs   = null,
        ?Task         $task         = null,
        ?WorkflowStep $workflowStep = null,
    ): TokenUsage {
        $usage = new TokenUsage($agent, $model, $inputTokens, $outputTokens, $durationMs, $task, $workflowStep);
        $this->em->persist($usage);
        $this->em->flush();
        return $usage;
    }

    /**
     * Global summary: total tokens, total calls, breakdown by agent.
     */
    public function getSummary(?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $rows = $this->tokenUsageRepository->sumByAgent($from, $to);
        $total = ['input' => 0, 'output' => 0, 'calls' => 0];

        foreach ($rows as $row) {
            $total['input']  += (int) $row['totalInput'];
            $total['output'] += (int) $row['totalOutput'];
            $total['calls']  += (int) $row['calls'];
        }

        return [
            'total'  => $total,
            'byAgent' => $rows,
        ];
    }

    /** @return TokenUsage[] */
    public function findByAgent(Agent $agent, int $limit = 100): array
    {
        return $this->tokenUsageRepository->findByAgent($agent, $limit);
    }

    /**
     * Returns project-scoped token data: a summary broken down by agent (with agent name resolved)
     * and the most recent individual entries.
     *
     * @return array{ summary: array{ total: array{input: int, output: int, calls: int}, byAgent: list<array{agentId: string, agentName: string, totalInput: int, totalOutput: int, calls: int}> }, entries: list<array<string, mixed>> }
     */
    public function getProjectTokens(Project $project, int $limit = 50): array
    {
        $rows    = $this->tokenUsageRepository->sumByProjectAndAgent($project);
        $entries = $this->tokenUsageRepository->findByProject($project, $limit);

        $total = ['input' => 0, 'output' => 0, 'calls' => 0];
        $byAgent = [];

        foreach ($rows as $row) {
            $total['input']  += (int) $row['totalInput'];
            $total['output'] += (int) $row['totalOutput'];
            $total['calls']  += (int) $row['calls'];

            $agentId   = $row['agentId'];
            $agentName = null;
            if ($agentId !== null) {
                $agent     = $this->agentRepository->find($agentId);
                $agentName = $agent?->getName();
            }

            $byAgent[] = [
                'agentId'     => $agentId,
                'agentName'   => $agentName ?? '—',
                'totalInput'  => (int) $row['totalInput'],
                'totalOutput' => (int) $row['totalOutput'],
                'calls'       => (int) $row['calls'],
            ];
        }

        return [
            'summary' => ['total' => $total, 'byAgent' => $byAgent],
            'entries' => array_map(fn(TokenUsage $u) => $this->serializeEntry($u), $entries),
        ];
    }

    /**
     * Returns token usage entries for a single task.
     *
     * @return list<array<string, mixed>>
     */
    public function findByTask(Task $task): array
    {
        return array_map(
            fn(TokenUsage $u) => $this->serializeEntry($u),
            $this->tokenUsageRepository->findByTask($task),
        );
    }

    /** @return array<string, mixed> */
    private function serializeEntry(TokenUsage $u): array
    {
        return [
            'id'           => (string) $u->getId(),
            'model'        => $u->getModel(),
            'inputTokens'  => $u->getInputTokens(),
            'outputTokens' => $u->getOutputTokens(),
            'totalTokens'  => $u->getTotalTokens(),
            'durationMs'   => $u->getDurationMs(),
            'task'         => $u->getTask() ? ['id' => (string) $u->getTask()->getId(), 'title' => $u->getTask()->getTitle()] : null,
            'createdAt'    => $u->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
