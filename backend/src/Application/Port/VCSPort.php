<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Port d'intégration avec les systèmes de gestion de version.
 * Implémenté par GitHubAdapter, GitLabAdapter, etc.
 */
interface VCSPort
{
    /**
     * Crée une nouvelle branche depuis une branche de base.
     */
    public function createBranch(string $repo, string $branchName, string $fromBranch = 'main'): bool;

    /**
     * Récupère le diff d'une Pull Request / Merge Request.
     */
    public function getPullRequestDiff(string $repo, int $prNumber): string;

    /**
     * Ouvre une Pull Request / Merge Request.
     */
    public function openPullRequest(string $repo, string $title, string $body, string $head, string $base = 'main'): array;

    /**
     * Ajoute un commentaire sur une Pull Request / Merge Request.
     */
    public function commentOnPullRequest(string $repo, int $prNumber, string $comment): bool;

    /**
     * Liste les Pull Requests / Merge Requests ouvertes.
     */
    public function listOpenPullRequests(string $repo): array;

    /**
     * Retourne le nom du connecteur (ex: "github", "gitlab").
     */
    public function getName(): string;

    /**
     * Vérifie que le connecteur est correctement configuré.
     */
    public function healthCheck(): bool;
}
