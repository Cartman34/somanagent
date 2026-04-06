<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Adapter\VCS;

use App\Port\VCSPort;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * VCSPort implementation for GitHub repository operations.
 */
class GitHubAdapter implements VCSPort
{
    private const BASE_URL = 'https://api.github.com';

    private Client $http;

    /**
     * Initializes the GitHub API client with the provided personal access token.
     */
    public function __construct(private readonly string $token)
    {
        $this->http = new Client([
            'base_uri' => self::BASE_URL,
            'headers'  => [
                'Authorization' => "Bearer {$token}",
                'Accept'        => 'application/vnd.github.v3+json',
                'User-Agent'    => 'SoManAgent/1.0',
            ],
        ]);
    }

    /**
     * Fetches repository metadata from GitHub.
     */
    public function getRepository(string $owner, string $repo): array
    {
        $response = $this->http->get("/repos/{$owner}/{$repo}");
        return json_decode((string) $response->getBody(), true);
    }

    /**
     * Creates a branch from the given source branch in the target repository.
     */
    public function createBranch(string $owner, string $repo, string $branch, string $from = 'main'): void
    {
        // Fetch the SHA of the source branch before creating the new ref.
        $refResponse = $this->http->get("/repos/{$owner}/{$repo}/git/ref/heads/{$from}");
        $sha         = json_decode((string) $refResponse->getBody(), true)['object']['sha'];

        $this->http->post("/repos/{$owner}/{$repo}/git/refs", [
            'json' => ['ref' => "refs/heads/{$branch}", 'sha' => $sha],
        ]);
    }

    /**
     * Opens a pull request from the source branch into the target base branch.
     */
    public function openPullRequest(string $owner, string $repo, string $title, string $body, string $head, string $base = 'main'): array
    {
        $response = $this->http->post("/repos/{$owner}/{$repo}/pulls", [
            'json' => compact('title', 'body', 'head', 'base'),
        ]);
        return json_decode((string) $response->getBody(), true);
    }

    /**
     * Returns the unified diff for a pull request.
     */
    public function getDiff(string $owner, string $repo, string $pullRequestId): string
    {
        $response = $this->http->get("/repos/{$owner}/{$repo}/pulls/{$pullRequestId}", [
            'headers' => ['Accept' => 'application/vnd.github.v3.diff'],
        ]);
        return (string) $response->getBody();
    }

    /**
     * Checks whether the configured token can reach the GitHub user endpoint.
     */
    public function healthCheck(): bool
    {
        try {
            $this->http->get('/user', ['timeout' => 5]);
            return true;
        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * Returns the provider slug handled by this adapter.
     */
    public function getProviderName(): string
    {
        return 'github';
    }
}
