<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Client;

/**
 * Outcome of an interactive process run: the exit code and the identifiers needed by `stop` later.
 */
final class InteractiveProcessResult
{
    /**
     * @param int $exitCode Exit code of the spawned process
     * @param int|null $clientPid Actual client process PID when known, null otherwise
     * @param int|null $processGroupId Process group id of the client, null when not applicable
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly ?int $clientPid,
        public readonly ?int $processGroupId,
    ) {}
}
