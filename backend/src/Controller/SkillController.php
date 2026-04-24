<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Input\Skill\CreateSkillDto;
use App\Dto\Input\Skill\ImportSkillDto;
use App\Dto\Input\Skill\UpdateSkillContentDto;
use App\Service\ApiErrorPayloadFactory;
use App\Service\SkillService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST controller managing skills: CRUD, import from skills.sh, and metadata.
 */
#[Route('/api/skills')]
class SkillController extends AbstractApiController
{
    /**
     * Initializes the controller with its dependencies.
     */
    public function __construct(
        private readonly SkillService $skillService,
        ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {
        parent::__construct($apiErrorPayloadFactory);
    }

    /**
     * Returns the list of all skills with their basic information.
     */
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

    /**
     * Returns a single skill by its ID with full details.
     *
     * @param string $id The skill UUID
     */
    #[Route('/{id}', name: 'skill_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $skill = $this->skillService->findById($id);
        if ($skill === null) {
            return $this->json($this->apiErrorPayloadFactory->create('skill.error.not_found'), Response::HTTP_NOT_FOUND);
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

    /**
     * Imports a skill from the registry using a source identifier.
     *
     * @param Request $request JSON body containing the "source" field
     */
    #[Route('/import', name: 'skill_import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        $dto = $this->tryParseDto(fn() => ImportSkillDto::fromArray($request->toArray()));
        if ($dto instanceof JsonResponse) {
            return $dto;
        }

        $skill = $this->skillService->importFromRegistry($dto);
        return $this->json(['id' => (string) $skill->getId(), 'slug' => $skill->getSlug()], Response::HTTP_CREATED);
    }

    /**
     * Creates a new custom skill.
     *
     * @param Request $request JSON body containing "slug", "name", "content", and optional "description"
     */
    #[Route('', name: 'skill_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $dto = $this->tryParseDto(fn() => CreateSkillDto::fromArray($request->toArray()));
        if ($dto instanceof JsonResponse) {
            return $dto;
        }

        $skill = $this->skillService->createCustom($dto);
        return $this->json(['id' => (string) $skill->getId(), 'slug' => $skill->getSlug()], Response::HTTP_CREATED);
    }

    /**
     * Updates the content of an existing skill.
     *
     * @param string  $id      The skill UUID
     * @param Request $request JSON body containing the "content" field
     */
    #[Route('/{id}/content', name: 'skill_update_content', methods: ['PATCH'])]
    public function updateContent(string $id, Request $request): JsonResponse
    {
        $skill = $this->skillService->findById($id);
        if ($skill === null) {
            return $this->json($this->apiErrorPayloadFactory->create('skill.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $dto = $this->tryParseDto(fn() => UpdateSkillContentDto::fromArray($request->toArray()));
        if ($dto instanceof JsonResponse) {
            return $dto;
        }

        $this->skillService->updateContent($skill, $dto);
        return $this->json(['id' => (string) $skill->getId(), 'slug' => $skill->getSlug()]);
    }

    /**
     * Deletes a skill by its ID.
     *
     * @param string $id The skill UUID
     */
    #[Route('/{id}', name: 'skill_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $skill = $this->skillService->findById($id);
        if ($skill === null) {
            return $this->json($this->apiErrorPayloadFactory->create('skill.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->skillService->delete($skill);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
