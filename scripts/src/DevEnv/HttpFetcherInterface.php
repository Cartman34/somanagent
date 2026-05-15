<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv;

/**
 * Fetches the raw body of a URL.
 *
 * Implementations include the live system fetcher (file_get_contents)
 * and a fake implementation used in tests.
 */
interface HttpFetcherInterface
{
    /**
     * Fetches the raw body of the given URL, or returns null on failure.
     *
     * The returned bytes may be gzip-compressed; decompression is the caller's responsibility.
     */
    public function fetch(string $url): ?string;
}
