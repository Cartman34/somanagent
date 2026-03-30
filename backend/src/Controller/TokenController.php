<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Service\ApiErrorPayloadFactory;
use App\Service\AgentService;
use App\Service\TokenUsageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/tokens')]
class TokenController extends AbstractController
{
    public function __construct(
        private readonly TokenUsageService $tokenUsageService,
        private readonly AgentService      $agentService,
        private readonly ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {}

    #[Route('/summary', name: 'token_summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        $from = $request->query->get('from') ? new \DateTimeImmutable($request->query->get('from')) : null;
        $to   = $request->query->get('to')   ? new \DateTimeImmutable($request->query->get('to'))   : null;

        return $this->json($this->tokenUsageService->getSummary($from, $to));
    }

    #[Route('/agents/{agentId}', name: 'token_by_agent', methods: ['GET'])]
    public function byAgent(string $agentId, Request $request): JsonResponse
    {
        $agent = $this->agentService->findById($agentId);
        if ($agent === null) {
            return $this->json($this->apiErrorPayloadFactory->create('tokens.agents.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $limit  = (int) ($request->query->get('limit', 100));
        $usages = $this->tokenUsageService->findByAgent($agent, $limit);

        return $this->json(array_map(fn($u) => [
            'id'           => (string) $u->getId(),
            'model'        => $u->getModel(),
            'inputTokens'  => $u->getInputTokens(),
            'outputTokens' => $u->getOutputTokens(),
            'totalTokens'  => $u->getTotalTokens(),
            'durationMs'   => $u->getDurationMs(),
            'task'         => $u->getTask() ? ['id' => (string) $u->getTask()->getId(), 'title' => $u->getTask()->getTitle()] : null,
            'createdAt'    => $u->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $usages));
    }
}
