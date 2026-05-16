<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Service;

/**
 * Single CLI flag presence check produced by LauncherFlagValidator.
 *
 * `$present` is true when the flag spelling was found as a delimited token in
 * the binary's `--help` output. False marks a flag that the launcher relies on
 * but the binary no longer advertises.
 */
final class LauncherFlagCheck
{
    public function __construct(
        public readonly string $flag,
        public readonly bool $present,
    ) {}
}
