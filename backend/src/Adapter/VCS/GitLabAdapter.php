<?php

declare(strict_types=1);

namespace App\Adapter\VCS;

use App\Port\VCSPort;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class GitLabAdapter implements VCSPort
{
    private Client $http;

    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl = 'https://gitlab.com',
    ) {
        $this->http = new Client([
            'base_uri' => rtrim($baseUrl, '/') . '/api/v4',
            'headers'  => [
                'PRIVATE-TOKEN' => $token,
                'Content-Type'  => 'application/json',
            ],
        ]);
    }

    public function getRepository(string $owner, string $repo): array
    {
        $id       = urlencode("{$owner}/{$repo}");
        $response = $this->http->get("/projects/{$id}");
        return json_decode((string) $response->getBody(), true);
    }

    public function createBranch(string $owner, string $repo, string $branch, string $from = 'main'): void
    {
        $id = urlencode("{$owner}/{$repo}");
        $this->http->post("/projects/{$id}/repository/branches", [
            'json' => ['branch' => $branch, 'ref' => $from],
        ]);
    }

    public function openPullRequest(string $owner, string $repo, string $title, string $body, string $head, string $base = 'main'): array
    {
        $id       = urlencode("{$owner}/{$repo}");
        $response = $this->http->post("/projects/{$id}/merge_requests", [
            'json' => [
                'title'           => $title,
                'description'     => $body,
                'source_branch'   => $head,
                'target_branch'   => $base,
            ],
        ]);
        return json_decode((string) $response->getBody(), true);
    }

    public function getDiff(string $owner, string $repo, string $pullRequestId): string
    {
        $id       = urlencode("{$owner}/{$repo}");
        $response = $this->http->get("/projects/{$id}/merge_requests/{$pullRequestId}/changes");
        $data     = json_decode((string) $response->getBody(), true);

        $diff = '';
        foreach ($data['changes'] ?? [] as $change) {
            $diff .= $change['diff'] ?? '';
        }
        return $diff;
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
        return 'gitlab';
    }
}
