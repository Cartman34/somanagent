<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Service;

/**
 * Single CLI flag presence check produced by LauncherFlagValidator.
 *
 * `$present` is true when the flag spelling was found as a delimited token in
 * the binary's `--help` output. False marks a flag that the launcher relies on
 * but the binary no longer advertises.
 */
final class LauncherFlagCheck
{
    /**
     * @param string $flag CLI option spelling as declared by the launcher (e.g. `--resume`, `-C`)
     * @param bool $present True when the flag was found as a delimited token in the binary `--help` output
     */
    public function __construct(
        public readonly string $flag,
        public readonly bool $present,
    )
    {
    }
}
