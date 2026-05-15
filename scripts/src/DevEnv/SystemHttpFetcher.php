<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv;

/**
 * Production implementation of HttpFetcherInterface using file_get_contents.
 *
 * Sends a somanagent-setup User-Agent on every request.
 */
final class SystemHttpFetcher implements HttpFetcherInterface
{
    /**
     * {@inheritdoc}
     */
    public function fetch(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'header'  => "User-Agent: somanagent-setup\r\n",
                'timeout' => 10,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);

        return $result !== false ? $result : null;
    }
}
