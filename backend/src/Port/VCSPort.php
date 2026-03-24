<?php

declare(strict_types=1);

namespace App\Port;

interface VCSPort
{
    public function getRepository(string $owner, string $repo): array;
    public function createBranch(string $owner, string $repo, string $branch, string $from = 'main'): void;
    public function openPullRequest(string $owner, string $repo, string $title, string $body, string $head, string $base = 'main'): array;
    public function getDiff(string $owner, string $repo, string $pullRequestId): string;
    public function healthCheck(): bool;
    public function getProviderName(): string;
}
