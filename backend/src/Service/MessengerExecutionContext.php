<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Stores per-message Messenger retry metadata for the current worker process.
 */
final class MessengerExecutionContext
{
    private ?array $current = null;

    /**
     * @param array{attempt: int, isRetry: bool, receiverName: string} $context
     */
    public function set(array $context): void
    {
        $this->current = $context;
    }

    /**
     * @return array{attempt: int, isRetry: bool, receiverName: string}|null
     */
    public function get(): ?array
    {
        return $this->current;
    }

    /**
     * Returns retry metadata with safe defaults when no worker context has been captured.
     *
     * @return array{attempt: int, isRetry: bool, receiverName: string}
     */
    public function getOrDefault(): array
    {
        return $this->current ?? [
            'attempt' => 1,
            'isRetry' => false,
            'receiverName' => 'async',
        ];
    }

    /**
     * Clears the worker-local retry metadata after the current message lifecycle ends.
     */
    public function clear(): void
    {
        $this->current = null;
    }
}
