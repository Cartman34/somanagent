<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\DevEnv;

use Sowapps\SoManAgent\Script\DevEnv\SourceQuerierInterface;
use Sowapps\SoManAgent\Script\DevEnv\CommandRunnerInterface;
use Sowapps\SoManAgent\Script\DevEnv\HttpFetcherInterface;

/**
 * Queries available package versions from real system sources.
 *
 * Uses apt-cache for default apt packages, npm view for npm packages,
 * and the GitHub Releases API for github-release packages.
 *
 * Non-default apt sources (PPAs, HTTPS repos) are queried by fetching their
 * Packages.gz file directly from the repository — no modification of
 * /etc/apt/sources.list.d/ or apt update is performed. This works without
 * root privileges and on machines where the source is not yet configured.
 *
 * PPA URL pattern:
 *   https://ppa.launchpadcontent.net/{user}/{name}/ubuntu/dists/{codename}/main/binary-{arch}/Packages.gz
 *
 * HTTPS repo URL pattern:
 *   {repo}/dists/{codename}/stable/binary-{arch}/Packages.gz
 */
final class SystemSourceQuerier implements SourceQuerierInterface
{
    /**
     * @param CommandRunnerInterface $commandRunner Runner for shell commands (apt-cache, npm, dpkg, lsb_release)
     * @param HttpFetcherInterface $httpFetcher Fetcher for HTTP requests (Packages.gz, GitHub API)
     */
    public function __construct(
        private readonly CommandRunnerInterface $commandRunner,
        private readonly HttpFetcherInterface $httpFetcher,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function queryVersions(string $installer, string $source, string $package): array
    {
        return match ($installer) {
            'apt'            => $this->queryApt($source, $package),
            'npm-global'     => $this->queryNpm($package),
            'github-release' => $this->queryGitHubReleases($source),
            default          => [],
        };
    }

    /**
     * Dispatches to the appropriate apt query strategy based on the source.
     *
     * @return list<string>
     */
    private function queryApt(string $source, string $package): array
    {
        if ($source === 'default') {
            return $this->queryAptDefault($package);
        }

        if (str_starts_with($source, 'ppa:')) {
            return $this->queryAptPpa($source, $package);
        }

        if (str_starts_with($source, 'https://') || str_starts_with($source, 'http://')) {
            return $this->queryAptRepo($source, $package);
        }

        return [];
    }

    /**
     * Queries the currently configured apt sources via apt-cache policy.
     *
     * @return list<string>
     */
    private function queryAptDefault(string $package): array
    {
        $output = $this->commandRunner->output(
            sprintf('apt-cache policy %s 2>/dev/null', escapeshellarg($package)),
        );

        if ($output === null) {
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
     * Queries a Launchpad PPA by fetching its Packages.gz file directly.
     *
     * @return list<string>
     */
    private function queryAptPpa(string $source, string $package): array
    {
        if (!preg_match('#^ppa:([^/]+)/([^/]+)$#', $source, $m)) {
            return [];
        }

        $codename = $this->getUbuntuCodename();
        if ($codename === null) {
            return [];
        }

        $url = sprintf(
            'https://ppa.launchpadcontent.net/%s/%s/ubuntu/dists/%s/main/binary-%s/Packages.gz',
            rawurlencode($m[1]),
            rawurlencode($m[2]),
            rawurlencode($codename),
            rawurlencode($this->getArchitecture()),
        );

        return $this->queryAptPackagesFile($url, $package);
    }

    /**
     * Queries a generic HTTPS apt repository by fetching its Packages.gz file directly.
     *
     * Assumes the repository uses the standard Debian layout with a "stable" component.
     *
     * @return list<string>
     */
    private function queryAptRepo(string $source, string $package): array
    {
        $codename = $this->getUbuntuCodename();
        if ($codename === null) {
            return [];
        }

        $url = sprintf(
            '%s/dists/%s/stable/binary-%s/Packages.gz',
            rtrim($source, '/'),
            rawurlencode($codename),
            rawurlencode($this->getArchitecture()),
        );

        return $this->queryAptPackagesFile($url, $package);
    }

    /**
     * Fetches a Packages (or Packages.gz) file and extracts all versions for a package.
     *
     * Attempts gzip decompression first; falls back to treating content as plain text
     * when decompression fails (e.g. plain Packages file or test fixture).
     *
     * @return list<string>
     */
    private function queryAptPackagesFile(string $url, string $package): array
    {
        $raw = $this->httpFetcher->fetch($url);
        if ($raw === null) {
            return [];
        }

        // Suppress the warning: failure on non-gzip input is handled by the fallback below
        $decompressed = @gzdecode($raw);
        $content = $decompressed !== false ? $decompressed : $raw;

        return $this->parsePackagesVersions($content, $package);
    }

    /**
     * Parses a Debian Packages file and returns all versions for the given package name.
     *
     * @return list<string>
     */
    private function parsePackagesVersions(string $content, string $package): array
    {
        $versions = [];
        $currentPackage = null;

        foreach (explode("\n", str_replace("\r", '', $content)) as $line) {
            if (str_starts_with($line, 'Package: ')) {
                $currentPackage = trim(substr($line, 9));
            } elseif ($currentPackage === $package && str_starts_with($line, 'Version: ')) {
                $version = trim(substr($line, 9));
                if ($version !== '') {
                    $versions[] = $version;
                }
            }
        }

        return $versions;
    }

    /**
     * Queries npm for the latest published version of a package.
     *
     * @return list<string>
     */
    private function queryNpm(string $package): array
    {
        $output = $this->commandRunner->output(
            sprintf('npm view %s version 2>/dev/null', escapeshellarg($package)),
        );

        if ($output === null) {
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

        $apiUrl = sprintf('https://api.github.com/repos/%s/releases/latest', $m[1]);
        $response = $this->httpFetcher->fetch($apiUrl);

        if ($response === null) {
            return [];
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['tag_name'])) {
            return [];
        }

        $version = ltrim((string) $data['tag_name'], 'v');

        return $version !== '' ? [$version] : [];
    }

    /**
     * Detects the Ubuntu distribution codename from /etc/os-release or lsb_release.
     */
    private function getUbuntuCodename(): ?string
    {
        if (is_readable('/etc/os-release')) {
            $content = file_get_contents('/etc/os-release');
            if (is_string($content) && preg_match('/^VERSION_CODENAME=(.+)$/m', $content, $m)) {
                return trim($m[1], '"\'');
            }
        }

        $output = $this->commandRunner->output('lsb_release -cs 2>/dev/null');
        if ($output !== null) {
            $codename = trim($output);
            if ($codename !== '') {
                return $codename;
            }
        }

        return null;
    }

    /**
     * Detects the system dpkg architecture, defaulting to amd64.
     */
    private function getArchitecture(): string
    {
        $output = $this->commandRunner->output('dpkg --print-architecture 2>/dev/null');
        if ($output !== null) {
            $arch = trim($output);
            if ($arch !== '') {
                return $arch;
            }
        }

        return 'amd64';
    }
}
