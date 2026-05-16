<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\DevEnv\Model;

/**
 * Resources created as a side effect of installing a dependency.
 *
 * Tracked so they can be cleaned up on uninstall.
 */
final class SideEffects
{
    /**
     * @param string|null $aptRepo Path to the added apt sources list file
     * @param string|null $gpgKey Path to the added GPG key file
     */
    public function __construct(
        public readonly ?string $aptRepo = null,
        public readonly ?string $gpgKey = null,
    ) {
    }

    /**
     * Returns true when no side effects are recorded.
     */
    public function isEmpty(): bool
    {
        return $this->aptRepo === null && $this->gpgKey === null;
    }
}
