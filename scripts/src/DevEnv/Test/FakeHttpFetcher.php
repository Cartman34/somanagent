<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv\Test;

use SoManAgent\Script\DevEnv\HttpFetcherInterface;

/**
 * Fake HTTP fetcher for unit tests.
 *
 * Returns predefined content keyed by URL prefix.
 * The first registered prefix that matches the requested URL wins.
 */
final class FakeHttpFetcher implements HttpFetcherInterface
{
    /**
     * @var array<string, string|null>
     */
    private array $responses = [];

    private int $callCount = 0;

    /**
     * Registers a response body for URLs starting with the given prefix.
     *
     * Pass null to simulate a fetch failure.
     */
    public function setResponse(string $urlPrefix, ?string $body): void
    {
        $this->responses[$urlPrefix] = $body;
    }

    /**
     * Returns the number of times fetch() was called.
     */
    public function getCallCount(): int
    {
        return $this->callCount;
    }

    /**
     * {@inheritdoc}
     */
    public function fetch(string $url): ?string
    {
        $this->callCount++;

        foreach ($this->responses as $prefix => $body) {
            if (str_starts_with($url, $prefix)) {
                return $body;
            }
        }

        return null;
    }
}
