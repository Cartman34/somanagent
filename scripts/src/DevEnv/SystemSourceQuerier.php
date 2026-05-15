<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv;

/**
 * Queries available package versions from real system sources.
 *
 * Uses apt-cache for apt packages, npm view for npm packages,
 * and the GitHub Releases API for github-release packages.
 */
final class SystemSourceQuerier implements SourceQuerierInterface
{
    /**
     * {@inheritdoc}
     */
    public function queryVersions(string $installer, string $source, string $package): array
    {
        return match ($installer) {
            'apt' => $this->queryApt($package),
            'npm-global' => $this->queryNpm($package),
            'github-release' => $this->queryGitHubReleases($source),
            default => [],
        };
    }

    /**
     * Queries apt-cache for the candidate version of a package.
     *
     * @return list<string>
     */
    private function queryApt(string $package): array
    {
        $output = shell_exec(sprintf('apt-cache policy %s 2>/dev/null', escapeshellarg($package)));
        if (!is_string($output)) {
            return [];
        }

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\s+Candidate:\s+(\S+)/', $line, $m) && $m[1] !== '(none)') {
                return [$m[1]];
            }
        }

        return [];
    }

    /**
     * Queries npm for the latest published version of a package.
     *
     * @return list<string>
     */
    private function queryNpm(string $package): array
    {
        $output = shell_exec(sprintf('npm view %s version 2>/dev/null', escapeshellarg($package)));
        if (!is_string($output)) {
            return [];
        }

        $version = trim($output);

        return $version !== '' ? [$version] : [];
    }

    /**
     * Queries the GitHub Releases API for the latest release version.
     *
     * Expects the source to contain a GitHub URL, e.g.:
     *   https://github.com/sst/opencode/releases
     *
     * @return list<string>
     */
    private function queryGitHubReleases(string $source): array
    {
        if (!preg_match('#github\.com/([^/]+/[^/]+?)(?:/releases)?$#', $source, $m)) {
            return [];
        }

        $repo = $m[1];
        $apiUrl = sprintf('https://api.github.com/repos/%s/releases/latest', $repo);
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: somanagent-setup\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => 5,
            ],
        ]);

        $response = @file_get_contents($apiUrl, false, $context);
        if ($response === false) {
            return [];
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['tag_name'])) {
            return [];
        }

        $version = ltrim((string) $data['tag_name'], 'v');

        return $version !== '' ? [$version] : [];
    }
}
