<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LogEvent;
use App\Entity\LogOccurrence;
use App\Repository\LogOccurrenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Uid\Uuid;

final class LogService
{
    private const OCCURRENCE_LEVELS = ['warning', 'error', 'critical'];
    private const OCCURRENCE_STATUSES = ['open', 'acknowledged', 'resolved', 'ignored'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LogOccurrenceRepository $occurrenceRepository,
        private readonly RequestCorrelationService $requestCorrelation,
        private readonly TranslatorInterface $translator,
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
     *   raw_payload?: array|null,
     *   title_i18n?: array{domain: string, key: string, parameters?: array<string, scalar|null>}|null,
     *   message_i18n?: array{domain: string, key: string, parameters?: array<string, scalar|null>}|null
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
        $titleI18n = $this->normalizeI18n($options['title_i18n'] ?? null);
        $messageI18n = $this->normalizeI18n($options['message_i18n'] ?? null);
        $renderedTitle = $this->renderI18n($title, $titleI18n);
        $renderedMessage = $this->renderI18n($message, $messageI18n);

        $event = new LogEvent($source, $category, $level, $renderedTitle, $renderedMessage);
        $event
            ->setTitleTranslation($titleI18n['domain'] ?? null, $titleI18n['key'] ?? null, $titleI18n['parameters'] ?? null)
            ->setMessageTranslation($messageI18n['domain'] ?? null, $messageI18n['key'] ?? null, $messageI18n['parameters'] ?? null)
            ->setFingerprint($options['fingerprint'] ?? $this->buildFingerprint($source, $category, $level, $renderedTitle, $renderedMessage, $options))
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
                $occurrence = (new LogOccurrence($category, $level, $event->getFingerprint(), $event->getTitle(), $event->getMessage(), $source))
                    ->setTitleTranslation($event->getTitleDomain(), $event->getTitleKey(), $event->getTitleParameters())
                    ->setMessageTranslation($event->getMessageDomain(), $event->getMessageKey(), $event->getMessageParameters())
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
     *   raw_payload?: array|null,
     *   title_i18n?: array{domain: string, key: string, parameters?: array<string, scalar|null>}|null
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
     * Updates the workflow status of a log occurrence.
     */
    public function updateOccurrenceStatus(LogOccurrence $occurrence, string $status): LogOccurrence
    {
        if (!in_array($status, self::OCCURRENCE_STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported log occurrence status: %s', $status));
        }

        $occurrence->setStatus($status);
        $this->em->flush();

        return $occurrence;
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

    /**
     * @param array{domain?: string, key?: string, parameters?: array<string, scalar|null>}|null $i18n
     * @return array{domain: string, key: string, parameters: array<string, scalar|null>}|null
     */
    private function normalizeI18n(?array $i18n): ?array
    {
        if (!is_array($i18n)) {
            return null;
        }

        $domain = $i18n['domain'] ?? null;
        $key = $i18n['key'] ?? null;

        if (!is_string($domain) || $domain === '' || !is_string($key) || $key === '') {
            return null;
        }

        return [
            'domain' => $domain,
            'key' => $key,
            'parameters' => is_array($i18n['parameters'] ?? null) ? $i18n['parameters'] : [],
        ];
    }

    /**
     * @param array{domain: string, key: string, parameters: array<string, scalar|null>}|null $i18n
     */
    private function renderI18n(string $fallback, ?array $i18n): string
    {
        if ($i18n === null) {
            return $fallback;
        }

        return $this->translator->trans($i18n['key'], $i18n['parameters'], $i18n['domain']);
    }
}
