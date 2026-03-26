<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\Task;
use App\Entity\TokenUsage;
use App\Entity\WorkflowStep;
use App\Repository\TokenUsageRepository;
use Doctrine\ORM\EntityManagerInterface;

class TokenUsageService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TokenUsageRepository   $tokenUsageRepository,
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
     * Résumé global : total tokens, total appels, répartition par agent.
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
}
