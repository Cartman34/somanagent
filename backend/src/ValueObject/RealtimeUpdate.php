<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\ValueObject;

use Symfony\Component\Uid\Uuid;

/**
 * Immutable realtime event envelope published through Mercure.
 */
final class RealtimeUpdate
{
    /**
     * @param list<string>          $topics
     * @param array<string, mixed>  $payload
     */
    private function __construct(
        private readonly string $id,
        private readonly array $topics,
        private readonly string $type,
        private readonly array $payload,
        private readonly \DateTimeImmutable $occurredAt,
        private readonly bool $private = false,
    ) {}

    /**
     * @param list<string>         $topics
     * @param array<string, mixed> $payload
     */
    public static function create(
        array $topics,
        string $type,
        array $payload = [],
        bool $private = false,
    ): self {
        return new self(
            id: Uuid::v7()->toRfc4122(),
            topics: array_values(array_unique($topics)),
            type: $type,
            payload: $payload,
            occurredAt: new \DateTimeImmutable(),
            private: $private,
        );
    }

    /**
     * Returns the stable Mercure update identifier.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /** @return list<string> */
    public function getTopics(): array
    {
        return $this->topics;
    }

    /**
     * Returns the normalized application event type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Returns when the update envelope was created.
     */
    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * Returns whether the update must be published as a private Mercure event.
     */
    public function isPrivate(): bool
    {
        return $this->private;
    }

    /**
     * Returns the public envelope forwarded to subscribers.
     *
     * @return array{id: string, type: string, occurredAt: string, payload: array<string, mixed>}
     */
    public function toEnvelope(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'occurredAt' => $this->occurredAt->format(\DateTimeInterface::ATOM),
            'payload' => $this->payload,
        ];
    }
}
