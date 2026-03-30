<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

/**
 * Resolves supported repository URLs and derives provider-specific web links.
 */
final class VcsRepositoryUrlService
{
    /**
     * Resolves a repository URL into normalized provider, owner/repo and web URL parts.
     *
     * Supports common HTTPS and SSH repository syntaxes for GitHub and GitLab.
     *
     * @return array{provider: string, host: string, owner: string, repo: string, webUrl: string}|null
     */
    public function resolve(?string $repositoryUrl): ?array
    {
        if ($repositoryUrl === null || trim($repositoryUrl) === '') {
            return null;
        }

        $repositoryUrl = trim($repositoryUrl);

        $resolved = $this->resolveHttpRepository($repositoryUrl)
            ?? $this->resolveSshRepository($repositoryUrl);

        if ($resolved === null) {
            return null;
        }

        $provider = $this->detectProvider($resolved['host']);
        if ($provider === null) {
            return null;
        }

        return [
            'provider' => $provider,
            'host' => $resolved['host'],
            'owner' => $resolved['owner'],
            'repo' => $resolved['repo'],
            'webUrl' => $resolved['webUrl'],
        ];
    }

    /**
     * Builds a provider-specific web URL pointing to the given branch when supported.
     */
    public function buildBranchUrl(?string $repositoryUrl, ?string $branchName): ?string
    {
        $resolved = $this->resolve($repositoryUrl);
        if ($resolved === null || $branchName === null || trim($branchName) === '') {
            return null;
        }

        $encodedBranch = implode('/', array_map('rawurlencode', explode('/', trim($branchName))));

        return match ($resolved['provider']) {
            'github' => $resolved['webUrl'] . '/tree/' . $encodedBranch,
            'gitlab' => $resolved['webUrl'] . '/-/tree/' . $encodedBranch,
            default => null,
        };
    }

    /**
     * Parses standard HTTP(S) repository URLs such as `https://github.com/acme/repo.git`.
     *
     * @return array{host: string, owner: string, repo: string, webUrl: string}|null
     */
    private function resolveHttpRepository(string $repositoryUrl): ?array
    {
        $parts = parse_url($repositoryUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return null;
        }

        if (!in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }

        $segments = $this->normalizePathSegments($parts['path']);
        if (count($segments) < 2) {
            return null;
        }

        $repo = array_pop($segments);
        $owner = implode('/', $segments);
        $baseUrl = sprintf('%s://%s', $parts['scheme'], $parts['host']);

        return [
            'host' => strtolower($parts['host']),
            'owner' => $owner,
            'repo' => $repo,
            'webUrl' => $baseUrl . '/' . $owner . '/' . $repo,
        ];
    }

    /**
     * Parses SSH repository URLs such as `git@github.com:acme/repo.git`.
     *
     * @return array{host: string, owner: string, repo: string, webUrl: string}|null
     */
    private function resolveSshRepository(string $repositoryUrl): ?array
    {
        if (preg_match('/^[^@]+@(?P<host>[^:]+):(?P<path>.+)$/', $repositoryUrl, $matches) !== 1) {
            return null;
        }

        $segments = $this->normalizePathSegments($matches['path']);
        if (count($segments) < 2) {
            return null;
        }

        $repo = array_pop($segments);
        $owner = implode('/', $segments);
        $host = strtolower($matches['host']);

        return [
            'host' => $host,
            'owner' => $owner,
            'repo' => $repo,
            'webUrl' => 'https://' . $host . '/' . $owner . '/' . $repo,
        ];
    }

    /**
     * Splits a repository path into clean owner/repo segments and strips a trailing `.git`.
     *
     * @return string[]
     */
    private function normalizePathSegments(string $path): array
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        if ($segments === []) {
            return [];
        }

        $segments[array_key_last($segments)] = preg_replace('/\.git$/', '', $segments[array_key_last($segments)]) ?? $segments[array_key_last($segments)];

        return $segments;
    }

    /**
     * Maps a repository host to the provider naming used by the VCS integration layer.
     */
    private function detectProvider(string $host): ?string
    {
        return match (true) {
            $host === 'github.com' => 'github',
            str_contains($host, 'gitlab') => 'gitlab',
            default => null,
        };
    }
}
