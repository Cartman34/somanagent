<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Normalized connector request containing prompt payload and execution context.
 */
final class ConnectorRequest
{
    /** Default working directory used when no ticket workspace has been allocated yet. */
    public const string DEFAULT_WORKING_DIRECTORY = '/var/www';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly Prompt $prompt,
        public readonly string $workingDirectory,
        public readonly ?string $sessionId = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * Builds a connector request from one prompt with an explicit working directory and an optional session identifier.
     */
    public static function fromPrompt(Prompt $prompt, string $workingDirectory, ?string $sessionId = null): self
    {
        return new self(prompt: $prompt, workingDirectory: $workingDirectory, sessionId: $sessionId);
    }
}
