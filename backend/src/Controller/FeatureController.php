<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Enum\FeatureStatus;
use App\Service\ApiErrorPayloadFactory;
use App\Service\FeatureService;
use App\Service\ProjectService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class FeatureController extends AbstractController
{
    /**
     * Initializes the controller with its dependencies.
     */
    public function __construct(
        private readonly FeatureService $featureService,
        private readonly ProjectService $projectService,
        private readonly ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {}

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

        $data = $request->toArray();
        if (empty($data['name'])) {
            return $this->json($this->apiErrorPayloadFactory->create('feature.validation.name_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $feature = $this->featureService->create($project, $data['name'], $data['description'] ?? null);
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
    #[Route('/features/{id}', name: 'feature_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $feature = $this->featureService->findById($id);
        if ($feature === null) {
            return $this->json($this->apiErrorPayloadFactory->create('feature.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data   = $request->toArray();
        $status = isset($data['status']) ? FeatureStatus::from($data['status']) : $feature->getStatus();
        $this->featureService->update($feature, $data['name'] ?? $feature->getName(), $data['description'] ?? null, $status);
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
