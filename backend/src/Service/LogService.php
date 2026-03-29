<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LogEvent;
use App\Entity\LogOccurrence;
use App\Repository\LogOccurrenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class LogService
{
    private const OCCURRENCE_LEVELS = ['warning', 'error', 'critical'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LogOccurrenceRepository $occurrenceRepository,
        private readonly RequestCorrelationService $requestCorrelation,
    ) {}

    /**
     * @param array{
     *   fingerprint?: string|null,
     *   project_id?: string|null,
     *   task_id?: string|null,
     *   agent_id?: string|null,
     *   exchange_ref?: string|null,
     *   request_ref?: string|null,
     *   trace_ref?: string|null,
     *   context?: array|null,
     *   stack?: string|null,
     *   origin?: string|null,
     *   raw_payload?: array|null
     * } $options
     */
    public function record(
        string $source,
        string $category,
        string $level,
        string $title,
        string $message,
        array $options = [],
    ): LogEvent {
        $event = new LogEvent($source, $category, $level, $title, $message);
        $event->setFingerprint($options['fingerprint'] ?? $this->buildFingerprint($source, $category, $level, $title, $message, $options))
            ->setProjectId($this->toUuid($options['project_id'] ?? null))
            ->setTaskId($this->toUuid($options['task_id'] ?? null))
            ->setAgentId($this->toUuid($options['agent_id'] ?? null))
            ->setExchangeRef($options['exchange_ref'] ?? null)
            ->setRequestRef($options['request_ref'] ?? $this->requestCorrelation->getCurrentRequestRef())
            ->setTraceRef($options['trace_ref'] ?? null)
            ->setContext($options['context'] ?? null)
            ->setStack($options['stack'] ?? null)
            ->setOrigin($options['origin'] ?? null)
            ->setRawPayload($options['raw_payload'] ?? null);

        $this->em->persist($event);

        if ($this->shouldAggregateOccurrence($event) && $event->getFingerprint() !== null) {
            $occurrence = $this->occurrenceRepository->findOneByFingerprint($category, $level, $event->getFingerprint());
            if ($occurrence === null) {
                $occurrence = (new LogOccurrence($category, $level, $event->getFingerprint(), $title, $message, $source))
                    ->setProjectId($event->getProjectId())
                    ->setTaskId($event->getTaskId())
                    ->setAgentId($event->getAgentId())
                    ->setLastLogEventId($event->getId())
                    ->setContextSnapshot($event->getContext());
                $this->em->persist($occurrence);
            } else {
                $occurrence->registerOccurrence($event);
            }
        }

        $this->em->flush();

        return $event;
    }

    /**
     * Records an exception as an error event while preserving the caller-provided correlation identifiers and context.
     *
     * @param array{
     *   fingerprint?: string|null,
     *   project_id?: string|null,
     *   task_id?: string|null,
     *   agent_id?: string|null,
     *   exchange_ref?: string|null,
     *   request_ref?: string|null,
     *   trace_ref?: string|null,
     *   context?: array|null,
     *   stack?: string|null,
     *   origin?: string|null,
     *   raw_payload?: array|null
     * } $options
     */
    public function recordError(string $source, string $title, \Throwable $exception, array $options = []): LogEvent
    {
        $context = $options['context'] ?? [];
        $context['exception_class'] = $exception::class;

        return $this->record(
            source: $source,
            category: 'error',
            level: 'error',
            title: $title,
            message: $exception->getMessage(),
            options: [
                ...$options,
                'context' => $context,
                'stack' => $exception->getTraceAsString(),
                'origin' => $exception->getFile() . ':' . $exception->getLine(),
            ],
        );
    }

    /**
     * Aggregates warnings and errors into occurrences so non-fatal frontend/infra degradations stay visible in the Logs UI.
     */
    private function shouldAggregateOccurrence(LogEvent $event): bool
    {
        return in_array($event->getLevel(), self::OCCURRENCE_LEVELS, true);
    }

    private function buildFingerprint(string $source, string $category, string $level, string $title, string $message, array $options): string
    {
        $seed = implode('|', [
            $source,
            $category,
            $level,
            $title,
            $options['origin'] ?? '',
            $options['context']['exception_class'] ?? '',
            $this->normalizeMessage($message),
        ]);

        return hash('sha256', $seed);
    }

    private function normalizeMessage(string $message): string
    {
        $normalized = preg_replace('/[0-9a-f]{8}-[0-9a-f-]{27,}/i', '{uuid}', $message) ?? $message;
        $normalized = preg_replace('/\d+/', '{n}', $normalized) ?? $normalized;

        return mb_substr($normalized, 0, 500);
    }

    private function toUuid(?string $value): ?Uuid
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Uuid::fromString($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
