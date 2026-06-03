<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\DevEnv\Installer;

use Sowapps\SoManAgent\Script\DevEnv\Model\LockEntry;
use Sowapps\SoManAgent\Script\Application;
use Sowapps\SoManAgent\Script\Console;
use Sowapps\SoManAgent\Script\DevEnv\PlannedDep;
use Sowapps\SoManAgent\Script\DevEnv\Installer\InstallerInterface;

/**
 * Installs AI CLI clients via npm (globally) or from GitHub releases.
 *
 * Handles the `clients` section of the lockfile.
 *
 * npm-global installer: runs `npm install -g {package}@{version}`.
 * github-release installer: downloads the Linux x86_64 binary asset from the
 *   GitHub releases API for the locked version and installs it to /usr/local/bin/.
 */
final class ClientsInstaller implements InstallerInterface
{
    /**
     * @param Application $app Command runner
     * @param Console $console Output helper
     */
    public function __construct(
        private readonly Application $app,
        private readonly Console $console,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function supports(PlannedDep $dep): bool
    {
        return in_array($dep->entry->installer, ['npm-global', 'github-release'], true);
    }

    /**
     * {@inheritdoc}
     *
     * @param list<PlannedDep> $deps
     * @return list<string>
     */
    public function getSimulatedCommands(array $deps): array
    {
        $commands = [];

        foreach ($deps as $dep) {
            if ($dep->action === PlannedDep::ACTION_SKIP) {
                continue;
            }

            $commands[] = match ($dep->entry->installer) {
                'npm-global' => sprintf(
                    'npm install -g %s',
                    escapeshellarg($dep->entry->package . '@' . $dep->entry->version),
                ),
                'github-release' => sprintf(
                    '# install %s %s from %s',
                    $dep->entry->package,
                    $dep->entry->version,
                    $dep->entry->source,
                ),
                default => sprintf('# unsupported installer: %s', $dep->entry->installer),
            };
        }

        return $commands;
    }

    /**
     * {@inheritdoc}
     *
     * @param list<PlannedDep> $deps
     * @return list<LockEntry>
     */
    public function install(array $deps): array
    {
        $now = new \DateTimeImmutable();
        $updatedEntries = [];

        foreach ($deps as $dep) {
            if ($dep->action === PlannedDep::ACTION_SKIP) {
                continue;
            }

            $this->console->info(sprintf('Installing %s (%s)', $dep->entry->key, $dep->entry->version));

            match ($dep->entry->installer) {
                'npm-global'     => $this->installNpm($dep),
                'github-release' => $this->installGitHubRelease($dep),
                default          => throw new \RuntimeException(
                    sprintf('Unsupported installer: %s', $dep->entry->installer),
                ),
            };

            $wasPreExisting = $dep->installedVersion !== null;
            $updatedEntries[] = $dep->entry->withApplied(
                wasPreExisting: $wasPreExisting,
                previousVersion: $dep->installedVersion,
                sideEffects: null,
                appliedAt: $now,
            );
        }

        return $updatedEntries;
    }

    /**
     * Installs an npm package globally at the exact locked version.
     *
     * @throws \RuntimeException on npm install failure
     */
    private function installNpm(PlannedDep $dep): void
    {
        $packageSpec = $dep->entry->package . '@' . $dep->entry->version;
        $code = $this->app->runCommand(sprintf('npm install -g %s', escapeshellarg($packageSpec)));

        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                'Failed to install %s via npm (exit %d)',
                $dep->entry->key,
                $code,
            ));
        }
    }

    /**
     * Downloads and installs a binary from a GitHub release asset.
     *
     * Queries the GitHub releases API for the specified version, finds the
     * Linux x86_64 asset, downloads it, extracts the binary if needed,
     * and installs it to /usr/local/bin/{package}.
     *
     * @throws \RuntimeException on download, extraction, or install failure
     */
    private function installGitHubRelease(PlannedDep $dep): void
    {
        $source = $dep->entry->source;
        $version = $dep->entry->version;
        $package = $dep->entry->package;

        if (!preg_match('#github\.com/([^/]+/[^/]+?)(?:/releases)?$#', $source, $m)) {
            throw new \RuntimeException(sprintf(
                'Cannot parse GitHub repository from source URL: %s',
                $source,
            ));
        }

        $repo = $m[1];
        $assetUrl = $this->resolveGitHubAssetUrl($repo, $version, $package);

        $tmpFile = sys_get_temp_dir() . '/' . $package . '_' . $version . '_linux_x86_64.tar.gz';

        $this->console->info(sprintf('Downloading %s from %s', $package, $assetUrl));

        $curlCmd = sprintf(
            'curl -fsSL -o %s %s',
            escapeshellarg($tmpFile),
            escapeshellarg($assetUrl),
        );
        $code = $this->app->runCommand($curlCmd);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf('Failed to download %s (exit %d)', $package, $code));
        }

        // Extract binary (try tar.gz first, then direct binary)
        $binPath = '/usr/local/bin/' . $package;
        if (str_ends_with($assetUrl, '.tar.gz') || str_ends_with($assetUrl, '.tgz')) {
            $extractDir = sys_get_temp_dir() . '/' . $package . '_extract_' . $version;
            @mkdir($extractDir, 0o755, true);
            $code = $this->app->runCommand(sprintf(
                'tar -xzf %s -C %s',
                escapeshellarg($tmpFile),
                escapeshellarg($extractDir),
            ));
            if ($code !== 0) {
                throw new \RuntimeException(sprintf('Failed to extract %s (exit %d)', $package, $code));
            }
            // Find the binary (first executable file)
            $extracted = glob($extractDir . '/*') ?: [];
            $binary = null;
            foreach ($extracted as $f) {
                if (is_file($f) && is_executable($f)) {
                    $binary = $f;
                    break;
                }
            }
            if ($binary === null) {
                // Try by package name
                $candidate = $extractDir . '/' . $package;
                $binary = is_file($candidate) ? $candidate : null;
            }
            if ($binary === null) {
                throw new \RuntimeException(sprintf('Could not find binary in extracted %s archive', $package));
            }

            $code = $this->app->runCommand(sprintf('sudo install -m 0755 %s %s', escapeshellarg($binary), escapeshellarg($binPath)));
            @array_map('unlink', glob($extractDir . '/*') ?: []);
            @rmdir($extractDir);
        } else {
            $code = $this->app->runCommand(sprintf('sudo install -m 0755 %s %s', escapeshellarg($tmpFile), escapeshellarg($binPath)));
        }

        @unlink($tmpFile);

        if ($code !== 0) {
            throw new \RuntimeException(sprintf('Failed to install %s binary (exit %d)', $package, $code));
        }
    }

    /**
     * Resolves the download URL for the Linux x86_64 asset of a specific GitHub release.
     *
     * @throws \RuntimeException when the release or a suitable asset cannot be found
     */
    private function resolveGitHubAssetUrl(string $repo, string $version, string $package): string
    {
        $apiUrl = sprintf('https://api.github.com/repos/%s/releases/tags/v%s', $repo, $version);
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: somanagent-setup\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => 10,
            ],
        ]);

        $response = @file_get_contents($apiUrl, false, $context);
        if ($response === false) {
            throw new \RuntimeException(sprintf(
                'Failed to query GitHub releases API for %s v%s',
                $repo,
                $version,
            ));
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['assets']) || !is_array($data['assets'])) {
            throw new \RuntimeException(sprintf(
                'No release found for %s v%s',
                $repo,
                $version,
            ));
        }

        // Find Linux x86_64 asset
        $patterns = [
            '/linux.*x86_64/i',
            '/Linux.*amd64/i',
            '/linux.*amd64/i',
            '/x86_64.*linux/i',
        ];

        foreach ($data['assets'] as $asset) {
            if (!is_array($asset) || !isset($asset['browser_download_url'], $asset['name'])) {
                continue;
            }
            $name = (string) $asset['name'];
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $name)) {
                    return (string) $asset['browser_download_url'];
                }
            }
        }

        throw new \RuntimeException(sprintf(
            'No Linux x86_64 asset found for %s v%s — available: %s',
            $repo,
            $version,
            implode(', ', array_column(array_filter($data['assets'], 'is_array'), 'name')),
        ));
    }
}
