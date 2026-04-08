<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Typed fixed collection for the shared connector health checks.
 */
final readonly class ConnectorHealthChecks
{
    /**
     * Builds the fixed shared collection of connector health checks.
     */
    public function __construct(
        public ConnectorHealthCheckResult $runtime,
        public ConnectorHealthCheckResult $auth,
        public ConnectorHealthCheckResult $promptTest,
        public ConnectorHealthCheckResult $models,
    ) {}

    /**
     * @return list<ConnectorHealthCheckResult>
     */
    public function all(): array
    {
        return [$this->runtime, $this->auth, $this->promptTest, $this->models];
    }

    /**
     * Returns the first degraded check in the shared battery, or null when all checks pass or are skipped.
     */
    public function firstDegraded(): ?ConnectorHealthCheckResult
    {
        foreach ($this->all() as $check) {
            if ($check->status === 'degraded') {
                return $check;
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        return [
            'runtime' => $this->runtime->toArray(),
            'auth' => $this->auth->toArray(),
            'prompt_test' => $this->promptTest->toArray(),
            'models' => $this->models->toArray(),
        ];
    }
}
