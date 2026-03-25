<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\WorkflowTrigger;
use App\Service\WorkflowService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/workflows')]
class WorkflowController extends AbstractController
{
    public function __construct(private readonly WorkflowService $workflowService) {}

    #[Route('', name: 'workflow_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map(fn($w) => [
            'id'          => (string) $w->getId(),
            'name'        => $w->getName(),
            'description' => $w->getDescription(),
            'trigger'     => $w->getTrigger()->value,
            'team'        => $w->getTeam() ? ['id' => (string) $w->getTeam()->getId(), 'name' => $w->getTeam()->getName()] : null,
            'isActive'    => $w->isActive(),
            'steps'       => $w->getSteps()->count(),
            'createdAt'   => $w->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->workflowService->findAll()));
    }

    #[Route('', name: 'workflow_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();
        if (empty($data['name'])) {
            return $this->json(['error' => 'The "name" field is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $trigger = WorkflowTrigger::tryFrom($data['trigger'] ?? 'manual') ?? WorkflowTrigger::Manual;

        $workflow = $this->workflowService->create(
            $data['name'],
            $trigger,
            $data['description'] ?? null,
            $data['teamId'] ?? null,
            $data['isActive'] ?? true,
        );

        return $this->json([
            'id'   => (string) $workflow->getId(),
            'name' => $workflow->getName(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'workflow_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $workflow = $this->workflowService->findById($id);
        if ($workflow === null) {
            return $this->json(['error' => 'Workflow not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'          => (string) $workflow->getId(),
            'name'        => $workflow->getName(),
            'description' => $workflow->getDescription(),
            'trigger'     => $workflow->getTrigger()->value,
            'team'        => $workflow->getTeam() ? ['id' => (string) $workflow->getTeam()->getId(), 'name' => $workflow->getTeam()->getName()] : null,
            'isActive'    => $workflow->isActive(),
            'steps'       => array_map(fn($s) => [
                'id'          => (string) $s->getId(),
                'stepOrder'   => $s->getStepOrder(),
                'name'        => $s->getName(),
                'roleSlug'    => $s->getRoleSlug(),
                'skillSlug'   => $s->getSkillSlug(),
                'inputConfig' => $s->getInputConfig(),
                'outputKey'   => $s->getOutputKey(),
                'condition'   => $s->getCondition(),
                'status'      => $s->getStatus()->value,
                'lastOutput'  => $s->getLastOutput(),
            ], $workflow->getSteps()->toArray()),
            'createdAt'   => $workflow->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt'   => $workflow->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}', name: 'workflow_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $workflow = $this->workflowService->findById($id);
        if ($workflow === null) {
            return $this->json(['error' => 'Workflow not found.'], Response::HTTP_NOT_FOUND);
        }

        $data    = $request->toArray();
        $trigger = WorkflowTrigger::tryFrom($data['trigger'] ?? $workflow->getTrigger()->value) ?? $workflow->getTrigger();

        $this->workflowService->update(
            $workflow,
            $data['name'] ?? $workflow->getName(),
            $trigger,
            $data['description'] ?? null,
            $data['teamId'] ?? null,
            $data['isActive'] ?? $workflow->isActive(),
        );

        return $this->json(['id' => (string) $workflow->getId(), 'name' => $workflow->getName()]);
    }

    #[Route('/{id}', name: 'workflow_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $workflow = $this->workflowService->findById($id);
        if ($workflow === null) {
            return $this->json(['error' => 'Workflow not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->workflowService->delete($workflow);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
