<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Input\Feature\CreateFeatureDto;
use App\Dto\Input\Feature\UpdateFeatureDto;
use App\Exception\ValidationException;
use App\Service\ApiErrorPayloadFactory;
use App\Service\FeatureService;
use App\Service\ProjectService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST controller managing features (epics) within projects.
 */
#[Route('/api')]
class FeatureController extends AbstractApiController
{
    /**
     * Initializes the controller with its dependencies.
     */
    public function __construct(
        private readonly FeatureService $featureService,
        private readonly ProjectService $projectService,
        ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {
        parent::__construct($apiErrorPayloadFactory);
    }

    /**
     * Lists all features for a given project.
     */
    #[Route('/projects/{projectId}/features', name: 'feature_list', methods: ['GET'])]
    public function list(string $projectId): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        if ($project === null) {
            return $this->json($this->apiErrorPayloadFactory->create('feature.error.project_not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this->json(array_map(fn($f) => [
            'id'          => (string) $f->getId(),
            'name'        => $f->getName(),
            'description' => $f->getDescription(),
            'status'      => $f->getStatus()->value,
            'createdAt'   => $f->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt'   => $f->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->featureService->findByProject($project)));
    }

    /**
     * Creates a new feature for a given project.
     */
    #[Route('/projects/{projectId}/features', name: 'feature_create', methods: ['POST'])]
    public function create(string $projectId, Request $request): JsonResponse
    {
        $project = $this->projectService->findById($projectId);
        if ($project === null) {
            return $this->json($this->apiErrorPayloadFactory->create('feature.error.project_not_found'), Response::HTTP_NOT_FOUND);
        }

        try {
            $dto = CreateFeatureDto::fromArray($request->toArray());
        } catch (ValidationException $e) {
            return $this->json($this->apiErrorPayloadFactory->fromValidationException($e), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $feature = $this->featureService->create($project, $dto);
        return $this->json(['id' => (string) $feature->getId(), 'name' => $feature->getName()], Response::HTTP_CREATED);
    }

    /**
     * Retrieves a single feature by ID.
     */
    #[Route('/features/{id}', name: 'feature_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $feature = $this->featureService->findById($id);
        if ($feature === null) {
            return $this->json($this->apiErrorPayloadFactory->create('feature.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'          => (string) $feature->getId(),
            'name'        => $feature->getName(),
            'description' => $feature->getDescription(),
            'status'      => $feature->getStatus()->value,
            'project'     => ['id' => (string) $feature->getProject()->getId(), 'name' => $feature->getProject()->getName()],
            'createdAt'   => $feature->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt'   => $feature->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Updates an existing feature.
     */
    #[Route('/features/{id}', name: 'feature_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $feature = $this->featureService->findById($id);
        if ($feature === null) {
            return $this->json($this->apiErrorPayloadFactory->create('feature.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $dto = $this->tryParseDto(fn() => UpdateFeatureDto::fromArray($request->toArray()));
        if ($dto instanceof JsonResponse) {
            return $dto;
        }
        $this->featureService->update($feature, $dto);
        return $this->json(['id' => (string) $feature->getId(), 'name' => $feature->getName()]);
    }

    /**
     * Deletes a feature by ID.
     */
    #[Route('/features/{id}', name: 'feature_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $feature = $this->featureService->findById($id);
        if ($feature === null) {
            return $this->json($this->apiErrorPayloadFactory->create('feature.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->featureService->delete($feature);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
