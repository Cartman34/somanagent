<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LogEvent;
use App\Entity\LogOccurrence;
use App\Repository\LogEventRepository;
use App\Repository\LogOccurrenceRepository;
use App\Service\ApiErrorPayloadFactory;
use App\Service\LogMessageRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST controller exposing structured log events and occurrences with filtering and rendering.
 */
#[Route('/api/logs')]
final class LogController extends AbstractController
{
    /**
     * Wires log repositories and rendering services used by the logs API.
     */
    public function __construct(
        private readonly LogOccurrenceRepository $occurrenceRepository,
        private readonly LogEventRepository $eventRepository,
        private readonly \App\Service\LogService $logService,
        private readonly ApiErrorPayloadFactory $apiErrorPayloadFactory,
        private readonly LogMessageRenderer $logMessageRenderer,
    ) {}

    /**
     * Lists log occurrences with optional filters and pagination.
     *
     * @param Request $request Query params: source, category, level, projectId, taskId, agentId, status, from, to, page, limit
     * @return JsonResponse {data: LogOccurrence[], total: int, page: int, limit: int}
     */
    #[Route('/occurrences', name: 'log_occurrence_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 25)));

        $filters = [
            'source' => $this->readString($request, 'source'),
            'category' => $this->readString($request, 'category'),
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
     * Lists raw log events with optional filters and pagination.
     *
     * @param Request $request Query params: source, category, level, projectId, taskId, agentId, from, to, page, limit
     * @return JsonResponse {data: LogEvent[], total: int, page: int, limit: int}
     */
    #[Route('/events', name: 'log_event_list', methods: ['GET'])]
    public function listEvents(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 25)));

        $filters = [
            'source' => $this->readString($request, 'source'),
            'category' => $this->readString($request, 'category'),
            'level' => $this->readString($request, 'level'),
            'projectId' => $this->readString($request, 'projectId'),
            'taskId' => $this->readString($request, 'taskId'),
            'agentId' => $this->readString($request, 'agentId'),
            'from' => $this->readDate($request, 'from'),
            'to' => $this->readDate($request, 'to'),
        ];

        $total = $this->eventRepository->countFiltered($filters);
        $events = $this->eventRepository->findFiltered($filters, $limit, ($page - 1) * $limit);

        return $this->json([
            'data' => array_map(fn (LogEvent $event) => $this->serializeEvent($event), $events),
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

    /**
     * Updates the triage status of an occurrence (`open`, `acknowledged`, `resolved`, `ignored`).
     *
     * TODO: Replace raw request parsing with a dedicated input DTO for this write endpoint.
     */
    #[Route('/occurrences/{id}/status', name: 'log_occurrence_status_update', methods: ['PATCH'])]
    public function updateStatus(Request $request, LogOccurrence $occurrence): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json($this->apiErrorPayloadFactory->createForField('message', 'log.validation.occurrence_invalid_json'), 400);
        }

        $status = $payload['status'] ?? null;
        if (!is_string($status) || $status === '') {
            return $this->json($this->apiErrorPayloadFactory->createForField('status', 'log.validation.occurrence_invalid_status'), 400);
        }

        try {
            $this->logService->updateOccurrenceStatus($occurrence, $status);
        } catch (\InvalidArgumentException) {
            return $this->json($this->apiErrorPayloadFactory->createForField('status', 'log.validation.occurrence_invalid_status'), 400);
        }

        return $this->json([
            'occurrence' => $this->serializeOccurrence($occurrence),
        ]);
    }

    /**
     * Accepts client-side observability events so frontend diagnostics can be centralized with backend logs.
     *
     * TODO: Replace raw request parsing with a dedicated input DTO for this write endpoint.
     */
    #[Route('/events', name: 'log_event_ingest', methods: ['POST'])]
    public function ingest(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json($this->apiErrorPayloadFactory->createForField('message', 'log.validation.ingest_invalid_json'), 400);
        }

        $titleI18n = $this->readI18nPayload($payload, 'titleI18n');
        $messageI18n = $this->readI18nPayload($payload, 'messageI18n');
        $source = $this->sanitizeSource($payload['source'] ?? null);
        $category = $this->sanitizeCategory($payload['category'] ?? null);
        $level = $this->sanitizeLevel($payload['level'] ?? null);
        $title = $this->sanitizeTitle($payload['title'] ?? null) ?? ($titleI18n !== null ? '' : null);
        $message = $this->sanitizeMessage($payload['message'] ?? null) ?? ($messageI18n !== null ? '' : null);

        if ($source === null || $category === null || $level === null || $title === null || $message === null) {
            return $this->json($this->apiErrorPayloadFactory->createForField('message', 'log.validation.ingest_invalid_fields'), 400);
        }

        $context = is_array($payload['context'] ?? null) ? $payload['context'] : null;
        $rawPayload = is_array($payload['rawPayload'] ?? null) ? $payload['rawPayload'] : null;
        $fingerprint = is_string($payload['fingerprint'] ?? null) && $payload['fingerprint'] !== ''
            ? mb_substr($payload['fingerprint'], 0, 64)
            : null;

        $event = $this->logService->record(
            source: $source,
            category: $category,
            level: $level,
            title: $title,
            message: $message,
            options: [
                'fingerprint' => $fingerprint,
                'project_id' => $this->readNullableString($payload, 'projectId'),
                'task_id' => $this->readNullableString($payload, 'taskId'),
                'agent_id' => $this->readNullableString($payload, 'agentId'),
                'exchange_ref' => $this->readNullableString($payload, 'exchangeRef'),
                'request_ref' => $this->readNullableString($payload, 'requestRef'),
                'trace_ref' => $this->readNullableString($payload, 'traceRef'),
                'context' => $context,
                'stack' => $this->readNullableString($payload, 'stack'),
                'origin' => $this->readNullableString($payload, 'origin'),
                'raw_payload' => $rawPayload,
                'title_i18n' => $titleI18n,
                'message_i18n' => $messageI18n,
            ],
        );

        return $this->json([
            'id' => (string) $event->getId(),
        ], 201);
    }

    private function serializeOccurrence(LogOccurrence $occurrence): array
    {
        return [
            'id' => (string) $occurrence->getId(),
            'category' => $occurrence->getCategory(),
            'level' => $occurrence->getLevel(),
            'fingerprint' => $occurrence->getFingerprint(),
            'title' => $this->logMessageRenderer->renderTitle($occurrence),
            'message' => $this->logMessageRenderer->renderMessage($occurrence),
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
            'i18n' => $this->logMessageRenderer->buildI18n($occurrence),
        ];
    }

    private function serializeEvent(LogEvent $event): array
    {
        return [
            'id' => (string) $event->getId(),
            'source' => $event->getSource(),
            'category' => $event->getCategory(),
            'level' => $event->getLevel(),
            'title' => $this->logMessageRenderer->renderTitle($event),
            'message' => $this->logMessageRenderer->renderMessage($event),
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
            'i18n' => $this->logMessageRenderer->buildI18n($event),
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

    private function sanitizeSource(mixed $value): ?string
    {
        return in_array($value, ['frontend', 'infra'], true) ? $value : null;
    }

    private function sanitizeCategory(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return mb_strlen($value) <= 20 ? $value : null;
    }

    private function sanitizeLevel(mixed $value): ?string
    {
        return in_array($value, ['info', 'warning', 'error', 'critical'], true) ? $value : null;
    }

    private function sanitizeTitle(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, 255);
    }

    private function sanitizeMessage(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * Reads an optional string field from a decoded JSON payload without silently coercing other scalar types.
     *
     * @param array<string, mixed> $payload
     */
    private function readNullableString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{domain: string, key: string, parameters?: array<string, scalar|null>}|null
     */
    private function readI18nPayload(array $payload, string $key): ?array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value)) {
            return null;
        }

        $domain = $value['domain'] ?? null;
        $translationKey = $value['key'] ?? null;
        if (!is_string($domain) || $domain === '' || !is_string($translationKey) || $translationKey === '') {
            return null;
        }

        $parameters = is_array($value['parameters'] ?? null) ? $value['parameters'] : [];

        return [
            'domain' => $domain,
            'key' => $translationKey,
            'parameters' => $parameters,
        ];
    }
}
