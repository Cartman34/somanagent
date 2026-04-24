<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Input\Workflow\CreateWorkflowDto;
use App\Dto\Input\Workflow\UpdateWorkflowDto;
use App\Service\ApiErrorPayloadFactory;
use App\Service\WorkflowService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST controller managing workflows, their steps, and activation lifecycle.
 */
#[Route('/api/workflows')]
class WorkflowController extends AbstractApiController
{
    /**
     * Initializes the controller with its dependencies.
     */
    public function __construct(
        private readonly WorkflowService $workflowService,
        ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {
        parent::__construct($apiErrorPayloadFactory);
    }

    /**
     * Returns the workflow catalogue with activation metadata.
     */
    #[Route('', name: 'workflow_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map(fn($workflow) => $this->buildWorkflowPayload($workflow, false), $this->workflowService->findAll()));
    }

    /**
     * Creates a new immutable workflow definition.
     */
    #[Route('', name: 'workflow_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $dto = $this->tryParseDto(fn() => CreateWorkflowDto::fromArray($request->toArray()));
        if ($dto instanceof JsonResponse) {
            return $dto;
        }

        $workflow = $this->workflowService->create($dto);

        return $this->json(['id' => (string) $workflow->getId(), 'name' => $workflow->getName()], Response::HTTP_CREATED);
    }

    /**
     * Returns the full detail of a workflow including its steps.
     */
    #[Route('/{id}', name: 'workflow_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $workflow = $this->workflowService->findById($id);
        if ($workflow === null) {
            return $this->json($this->apiErrorPayloadFactory->create('workflow.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->buildWorkflowPayload($workflow, true));
    }

    /**
     * Updates an inactive workflow definition.
     */
    #[Route('/{id}', name: 'workflow_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $workflow = $this->workflowService->findById($id);
        if ($workflow === null) {
            return $this->json($this->apiErrorPayloadFactory->create('workflow.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (!$this->workflowService->canEdit($workflow)) {
            return $this->json($this->apiErrorPayloadFactory->create('workflow.error.immutable'), Response::HTTP_CONFLICT);
        }

        $dto = $this->tryParseDto(fn() => UpdateWorkflowDto::fromArray($request->toArray()));
        if ($dto instanceof JsonResponse) {
            return $dto;
        }
        $workflow = $this->workflowService->update($workflow, $dto);

        return $this->json($this->buildWorkflowPayload($workflow, true));
    }

    /**
     * Deletes an inactive workflow definition.
     */
    #[Route('/{id}', name: 'workflow_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $workflow = $this->workflowService->findById($id);
        if ($workflow === null) {
            return $this->json($this->apiErrorPayloadFactory->create('workflow.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (!$this->workflowService->canEdit($workflow)) {
            return $this->json($this->apiErrorPayloadFactory->create('workflow.error.immutable'), Response::HTTP_CONFLICT);
        }

        $this->workflowService->delete($workflow);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Creates an inactive copy of an existing workflow.
     */
    #[Route('/{id}/duplicate', name: 'workflow_duplicate', methods: ['POST'])]
    public function duplicate(string $id): JsonResponse
    {
        $workflow = $this->workflowService->findById($id);
        if ($workflow === null) {
            return $this->json($this->apiErrorPayloadFactory->create('workflow.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $duplicate = $this->workflowService->duplicate($workflow);

        return $this->json([
            'id' => (string) $duplicate->getId(),
            'name' => $duplicate->getName(),
            'isActive' => $duplicate->isActive(),
        ], Response::HTTP_CREATED);
    }

    /**
     * Activates an existing workflow.
     */
    #[Route('/{id}/activate', name: 'workflow_activate', methods: ['POST'])]
    public function activate(string $id): JsonResponse
    {
        $workflow = $this->workflowService->findById($id);
        if ($workflow === null) {
            return $this->json($this->apiErrorPayloadFactory->create('workflow.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $workflow = $this->workflowService->activate($workflow);

        return $this->json([
            'id' => (string) $workflow->getId(),
            'isActive' => $workflow->isActive(),
        ]);
    }

    /**
     * Deactivates an existing workflow.
     */
    #[Route('/{id}/deactivate', name: 'workflow_deactivate', methods: ['POST'])]
    public function deactivate(string $id): JsonResponse
    {
        $workflow = $this->workflowService->findById($id);
        if ($workflow === null) {
            return $this->json($this->apiErrorPayloadFactory->create('workflow.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        try {
            $workflow = $this->workflowService->deactivate($workflow);
        } catch (\LogicException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromMessage($e->getMessage()), Response::HTTP_CONFLICT);
        }

        return $this->json([
            'id' => (string) $workflow->getId(),
            'isActive' => $workflow->isActive(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWorkflowPayload(\App\Entity\Workflow $workflow, bool $withSteps): array
    {
        $payload = [
            'id' => (string) $workflow->getId(),
            'name' => $workflow->getName(),
            'description' => $workflow->getDescription(),
            'trigger' => $workflow->getTrigger()->value,
            'isActive' => $workflow->isActive(),
            'isEditable' => $this->workflowService->canEdit($workflow),
            'steps' => $withSteps
                ? array_map(fn($step) => [
                    'id' => (string) $step->getId(),
                    'stepOrder' => $step->getStepOrder(),
                    'name' => $step->getName(),
                    'inputConfig' => $step->getInputConfig(),
                    'outputKey' => $step->getOutputKey(),
                    'transitionMode' => $step->getTransitionMode()->value,
                    'condition' => $step->getCondition(),
                    'status' => $step->getStatus()->value,
                    'lastOutput' => $step->getLastOutput(),
                    'actions' => array_map(fn($action) => [
                        'id' => (string) $action->getId(),
                        'createWithTicket' => $action->shouldCreateWithTicket(),
                        'agentAction' => [
                            'id' => (string) $action->getAgentAction()->getId(),
                            'key' => $action->getAgentAction()->getKey(),
                            'label' => $action->getAgentAction()->getLabel(),
                            'allowedEffects' => $action->getAgentAction()->getAllowedEffects(),
                            'role' => $action->getAgentAction()->getRole() ? [
                                'id' => (string) $action->getAgentAction()->getRole()->getId(),
                                'slug' => $action->getAgentAction()->getRole()->getSlug(),
                                'name' => $action->getAgentAction()->getRole()->getName(),
                            ] : null,
                            'skill' => $action->getAgentAction()->getSkill() ? [
                                'id' => (string) $action->getAgentAction()->getSkill()->getId(),
                                'slug' => $action->getAgentAction()->getSkill()->getSlug(),
                                'name' => $action->getAgentAction()->getSkill()->getName(),
                            ] : null,
                        ],
                    ], $step->getActions()->toArray()),
                ], $workflow->getSteps()->toArray())
                : $workflow->getSteps()->count(),
            'createdAt' => $workflow->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];

        if ($withSteps) {
            $payload['updatedAt'] = $workflow->getUpdatedAt()->format(\DateTimeInterface::ATOM);
        }

        return $payload;
    }
}
