<?php

declare(strict_types=1);

namespace App\Adapter\VCS;

use App\Port\VCSPort;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class GitHubAdapter implements VCSPort
{
    private const BASE_URL = 'https://api.github.com';

    private Client $http;

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

    public function getRepository(string $owner, string $repo): array
    {
        $response = $this->http->get("/repos/{$owner}/{$repo}");
        return json_decode((string) $response->getBody(), true);
    }

    public function createBranch(string $owner, string $repo, string $branch, string $from = 'main'): void
    {
        // Récupère le SHA de la branche source
        $refResponse = $this->http->get("/repos/{$owner}/{$repo}/git/ref/heads/{$from}");
        $sha         = json_decode((string) $refResponse->getBody(), true)['object']['sha'];

        $this->http->post("/repos/{$owner}/{$repo}/git/refs", [
            'json' => ['ref' => "refs/heads/{$branch}", 'sha' => $sha],
        ]);
    }

    public function openPullRequest(string $owner, string $repo, string $title, string $body, string $head, string $base = 'main'): array
    {
        $response = $this->http->post("/repos/{$owner}/{$repo}/pulls", [
            'json' => compact('title', 'body', 'head', 'base'),
        ]);
        return json_decode((string) $response->getBody(), true);
    }

    public function getDiff(string $owner, string $repo, string $pullRequestId): string
    {
        $response = $this->http->get("/repos/{$owner}/{$repo}/pulls/{$pullRequestId}", [
            'headers' => ['Accept' => 'application/vnd.github.v3.diff'],
        ]);
        return (string) $response->getBody();
    }

    public function healthCheck(): bool
    {
        try {
            $this->http->get('/user', ['timeout' => 5]);
            return true;
        } catch (GuzzleException) {
            return false;
        }
    }

    public function getProviderName(): string
    {
        return 'github';
    }
}
