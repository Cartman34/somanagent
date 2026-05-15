<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv;

/**
 * Checks whether a version string satisfies a semver-style constraint.
 *
 * Supported operators: >=, >, <=, <, =, !=, ^, ~
 * Versions are normalized by stripping non-numeric suffixes before comparison
 * (e.g. "8.4.3-1ubuntu1.0~22.04.1" → "8.4.3").
 */
final class VersionConstraint
{
    /**
     * Returns true when $version satisfies the given constraint string.
     *
     * @throws \InvalidArgumentException when the constraint operator is not recognized
     */
    public function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);

        if (preg_match('/^(>=|<=|!=|>|<|=)(.+)$/', $constraint, $m)) {
            $operator = $m[1];
            $constraintVersion = trim($m[2]);
        } elseif (preg_match('/^(\^|~)(.+)$/', $constraint, $m)) {
            $operator = $m[1];
            $constraintVersion = trim($m[2]);
        } else {
            $operator = '=';
            $constraintVersion = $constraint;
        }

        $normalized = $this->normalize($version);
        $constraintNormalized = $this->normalize($constraintVersion);

        return match ($operator) {
            '>=' => version_compare($normalized, $constraintNormalized, '>='),
            '>' => version_compare($normalized, $constraintNormalized, '>'),
            '<=' => version_compare($normalized, $constraintNormalized, '<='),
            '<' => version_compare($normalized, $constraintNormalized, '<'),
            '=', '==' => version_compare($normalized, $constraintNormalized, '=='),
            '!=' => version_compare($normalized, $constraintNormalized, '!='),
            '^' => $this->satisfiesCaret($normalized, $constraintNormalized),
            '~' => $this->satisfiesTilde($normalized, $constraintNormalized),
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported constraint operator: %s',
                $operator,
            )),
        };
    }

    /**
     * Returns the highest version from the list that satisfies the constraint, or null if none.
     *
     * @param list<string> $versions
     */
    public function highest(array $versions, string $constraint): ?string
    {
        $satisfying = array_values(array_filter(
            $versions,
            fn(string $v): bool => $this->satisfies($v, $constraint),
        ));

        if ($satisfying === []) {
            return null;
        }

        usort($satisfying, fn(string $a, string $b): int => version_compare(
            $this->normalize($b),
            $this->normalize($a),
        ));

        return $satisfying[0];
    }

    /**
     * Strips non-numeric suffixes from a version string to produce a comparable form.
     *
     * Examples:
     *   "8.4.3-1ubuntu1.0~22.04.1" → "8.4.3"
     *   "24.0.7" → "24.0.7"
     *   "1.0.62" → "1.0.62"
     */
    public function normalize(string $version): string
    {
        if (preg_match('/^(\d+(?:\.\d+)*)/', ltrim($version, 'v'), $m)) {
            return $m[1];
        }

        return $version;
    }

    /**
     * Caret constraint: allows changes that do not modify the left-most non-zero digit.
     *
     * Examples: ^1.2.3 := >=1.2.3 <2.0.0 ; ^0.3.0 := >=0.3.0 <0.4.0
     */
    private function satisfiesCaret(string $version, string $constraintVersion): bool
    {
        $parts = explode('.', $constraintVersion);
        $major = (int) ($parts[0] ?? 0);
        $minor = (int) ($parts[1] ?? 0);

        if ($major > 0) {
            $upper = ($major + 1) . '.0.0';
        } elseif ($minor > 0) {
            $upper = '0.' . ($minor + 1) . '.0';
        } else {
            $upper = '0.0.' . ((int) ($parts[2] ?? 0) + 1);
        }

        return version_compare($version, $constraintVersion, '>=')
            && version_compare($version, $upper, '<');
    }

    /**
     * Tilde constraint: allows patch-level changes when minor is specified, otherwise minor-level.
     *
     * Examples: ~1.2.3 := >=1.2.3 <1.3.0 ; ~1.2 := >=1.2.0 <1.3.0 ; ~1 := >=1.0.0 <2.0.0
     */
    private function satisfiesTilde(string $version, string $constraintVersion): bool
    {
        $parts = explode('.', $constraintVersion);
        $major = (int) ($parts[0] ?? 0);
        $minor = (int) ($parts[1] ?? 0);

        if (count($parts) >= 2) {
            $upper = $major . '.' . ($minor + 1) . '.0';
        } else {
            $upper = ($major + 1) . '.0.0';
        }

        return version_compare($version, $constraintVersion, '>=')
            && version_compare($version, $upper, '<');
    }
}
