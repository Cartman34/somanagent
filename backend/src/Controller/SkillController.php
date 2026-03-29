<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ApiErrorPayloadFactory;
use App\Service\SkillService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/skills')]
class SkillController extends AbstractController
{
    public function __construct(
        private readonly SkillService $skillService,
        private readonly ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {}

    #[Route('', name: 'skill_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map(fn($s) => [
            'id'          => (string) $s->getId(),
            'slug'        => $s->getSlug(),
            'name'        => $s->getName(),
            'description' => $s->getDescription(),
            'source'      => $s->getSource()->value,
            'sourceLabel' => $s->getSource()->label(),
            'updatedAt'   => $s->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->skillService->findAll()));
    }

    #[Route('/{id}', name: 'skill_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $skill = $this->skillService->findById($id);
        if ($skill === null) {
            return $this->json($this->apiErrorPayloadFactory->create('skills.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'             => (string) $skill->getId(),
            'slug'           => $skill->getSlug(),
            'name'           => $skill->getName(),
            'description'    => $skill->getDescription(),
            'source'         => $skill->getSource()->value,
            'originalSource' => $skill->getOriginalSource(),
            'content'        => $skill->getContent(),
            'filePath'       => $skill->getFilePath(),
            'createdAt'      => $skill->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt'      => $skill->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/import', name: 'skill_import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        $data = $request->toArray();
        if (empty($data['source'])) {
            return $this->json($this->apiErrorPayloadFactory->create('skills.validation.source_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $skill = $this->skillService->importFromRegistry($data['source']);
        return $this->json(['id' => (string) $skill->getId(), 'slug' => $skill->getSlug()], Response::HTTP_CREATED);
    }

    #[Route('', name: 'skill_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();
        if (empty($data['slug']) || empty($data['name']) || empty($data['content'])) {
            return $this->json($this->apiErrorPayloadFactory->create('skills.validation.create_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $skill = $this->skillService->createCustom($data['slug'], $data['name'], $data['content'], $data['description'] ?? null);
        return $this->json(['id' => (string) $skill->getId(), 'slug' => $skill->getSlug()], Response::HTTP_CREATED);
    }

    #[Route('/{id}/content', name: 'skill_update_content', methods: ['PUT'])]
    public function updateContent(string $id, Request $request): JsonResponse
    {
        $skill = $this->skillService->findById($id);
        if ($skill === null) {
            return $this->json($this->apiErrorPayloadFactory->create('skills.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        if (empty($data['content'])) {
            return $this->json($this->apiErrorPayloadFactory->create('skills.validation.content_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->skillService->updateContent($skill, $data['content']);
        return $this->json(['id' => (string) $skill->getId(), 'slug' => $skill->getSlug()]);
    }

    #[Route('/{id}', name: 'skill_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $skill = $this->skillService->findById($id);
        if ($skill === null) {
            return $this->json($this->apiErrorPayloadFactory->create('skills.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->skillService->delete($skill);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
