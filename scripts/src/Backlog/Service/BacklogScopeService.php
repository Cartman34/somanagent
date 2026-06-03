<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Service;

/**
 * Validates and resolves entry scopes.
 *
 * A scope name maps to a list of directory prefixes (each ending with `/`) configured in
 * `local/backlog/config.yaml` under `scopes:`. A null scope means ALL (no restriction).
 * The string `ALL` is reserved and forbidden as a scope name in the config.
 */
final class BacklogScopeService
{
    /** Reserved scope name that must never appear in the config. */
    public const RESERVED_ALL = 'ALL';

    /**
     * Resolves the effective scope name for an entry, applying inheritance from the parent feature.
     *
     * A child task with no explicit scope inherits the parent feature's scope.
     * A null result means the effective scope is ALL (unrestricted).
     */
    public function resolveEffectiveScopeName(?string $entryScopeName, ?string $parentScopeName): ?string
    {
        return $entryScopeName ?? $parentScopeName;
    }

    /**
     * Resolves directory prefixes for a scope name, or null when the scope is ALL.
     *
     * @param array<string, list<string>> $scopes The scopes map from BacklogConfig::getScopes()
     * @return list<string>|null Null means ALL (no restriction); empty list means no directories are allowed.
     */
    public function resolveScopeDirs(?string $scopeName, array $scopes): ?array
    {
        if ($scopeName === null) {
            return null;
        }

        return $scopes[$scopeName] ?? [];
    }

    /**
     * Returns the files that violate the scope (i.e. not under any of the given directory prefixes).
     *
     * @param list<string> $files Relative file paths to check
     * @param list<string> $scopeDirs Directory prefixes (each ending with `/`)
     * @return list<string> Files that are outside every scope directory
     */
    public function collectScopeViolations(array $files, array $scopeDirs): array
    {
        $violations = [];
        foreach ($files as $file) {
            if (!$this->isFileInScopeDirs($file, $scopeDirs)) {
                $violations[] = $file;
            }
        }

        return $violations;
    }

    /**
     * Returns true when every directory in $taskScopeDirs is within some directory of $featureScopeDirs.
     *
     * A task scope directory is "within" a feature scope directory when it starts with that
     * feature directory prefix.
     *
     * @param list<string> $taskScopeDirs
     * @param list<string> $featureScopeDirs
     */
    public function isTaskScopeWithinFeatureScope(array $taskScopeDirs, array $featureScopeDirs): bool
    {
        foreach ($taskScopeDirs as $taskDir) {
            $covered = false;
            foreach ($featureScopeDirs as $featureDir) {
                if (str_starts_with($taskDir, $featureDir)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns true when the file is under at least one of the scope directories.
     *
     * @param list<string> $scopeDirs
     */
    private function isFileInScopeDirs(string $file, array $scopeDirs): bool
    {
        foreach ($scopeDirs as $dir) {
            if (str_starts_with($file, $dir)) {
                return true;
            }
        }

        return false;
    }
}
