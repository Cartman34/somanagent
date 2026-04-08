<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Immutable advisory emitted when evaluating connector model selection.
 */
final readonly class AgentModelAdvisory
{
    /**
     * Builds the immutable advisory emitted when model selection needs user-facing guidance.
     */
    public function __construct(
        public string $level,
        public string $code,
        public string $message,
    ) {}

    /**
     * Returns the advisory payload serialized for API responses.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'code' => $this->code,
            'message' => $this->message,
        ];
    }
}
