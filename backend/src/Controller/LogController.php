<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LogEvent;
use App\Entity\LogOccurrence;
use App\Repository\LogEventRepository;
use App\Repository\LogOccurrenceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/logs')]
final class LogController extends AbstractController
{
    public function __construct(
        private readonly LogOccurrenceRepository $occurrenceRepository,
        private readonly LogEventRepository $eventRepository,
    ) {}

    /**
     * Lists log occurrences with optional filters and pagination.
     *
     * @param Request $request Query params: source, level, projectId, taskId, agentId, status, from, to, page, limit
     * @return JsonResponse {data: LogOccurrence[], total: int, page: int, limit: int}
     */
    #[Route('/occurrences', name: 'log_occurrence_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 25)));

        $filters = [
            'source' => $this->readString($request, 'source'),
            'level' => $this->readString($request, 'level'),
            'projectId' => $this->readString($request, 'projectId'),
            'taskId' => $this->readString($request, 'taskId'),
            'agentId' => $this->readString($request, 'agentId'),
            'status' => $this->readString($request, 'status'),
            'from' => $this->readDate($request, 'from'),
            'to' => $this->readDate($request, 'to'),
        ];

        $total = $this->occurrenceRepository->countFiltered($filters);
        $occurrences = $this->occurrenceRepository->findFiltered($filters, $limit, ($page - 1) * $limit);

        return $this->json([
            'data' => array_map(fn (LogOccurrence $occurrence) => $this->serializeOccurrence($occurrence), $occurrences),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * Returns a single log occurrence with its associated events.
     *
     * @param LogOccurrence $occurrence Resolved by Symfony ParamConverter via {id}
     * @return JsonResponse {occurrence: LogOccurrence, events: LogEvent[]}
     */
    #[Route('/occurrences/{id}', name: 'log_occurrence_detail', methods: ['GET'])]
    public function detail(LogOccurrence $occurrence): JsonResponse
    {
        $events = $this->eventRepository->findByOccurrenceSignature(
            $occurrence->getCategory(),
            $occurrence->getLevel(),
            $occurrence->getFingerprint(),
            100,
        );

        return $this->json([
            'occurrence' => $this->serializeOccurrence($occurrence),
            'events' => array_map(fn (LogEvent $event) => $this->serializeEvent($event), $events),
        ]);
    }

    private function serializeOccurrence(LogOccurrence $occurrence): array
    {
        return [
            'id' => (string) $occurrence->getId(),
            'category' => $occurrence->getCategory(),
            'level' => $occurrence->getLevel(),
            'fingerprint' => $occurrence->getFingerprint(),
            'title' => $occurrence->getTitle(),
            'message' => $occurrence->getMessage(),
            'source' => $occurrence->getSource(),
            'projectId' => $occurrence->getProjectId()?->toRfc4122(),
            'taskId' => $occurrence->getTaskId()?->toRfc4122(),
            'agentId' => $occurrence->getAgentId()?->toRfc4122(),
            'firstSeenAt' => $occurrence->getFirstSeenAt()->format(\DateTimeInterface::ATOM),
            'lastSeenAt' => $occurrence->getLastSeenAt()->format(\DateTimeInterface::ATOM),
            'occurrenceCount' => $occurrence->getOccurrenceCount(),
            'status' => $occurrence->getStatus(),
            'lastLogEventId' => $occurrence->getLastLogEventId()?->toRfc4122(),
            'contextSnapshot' => $occurrence->getContextSnapshot(),
        ];
    }

    private function serializeEvent(LogEvent $event): array
    {
        return [
            'id' => (string) $event->getId(),
            'source' => $event->getSource(),
            'category' => $event->getCategory(),
            'level' => $event->getLevel(),
            'title' => $event->getTitle(),
            'message' => $event->getMessage(),
            'fingerprint' => $event->getFingerprint(),
            'projectId' => $event->getProjectId()?->toRfc4122(),
            'taskId' => $event->getTaskId()?->toRfc4122(),
            'agentId' => $event->getAgentId()?->toRfc4122(),
            'exchangeRef' => $event->getExchangeRef(),
            'requestRef' => $event->getRequestRef(),
            'traceRef' => $event->getTraceRef(),
            'context' => $event->getContext(),
            'stack' => $event->getStack(),
            'origin' => $event->getOrigin(),
            'rawPayload' => $event->getRawPayload(),
            'occurredAt' => $event->getOccurredAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function readString(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function readDate(Request $request, string $key): ?\DateTimeImmutable
    {
        $value = $request->query->get($key);
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
