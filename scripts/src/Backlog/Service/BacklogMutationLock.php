<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Service;

/**
 * Advisory file lock that serialises concurrent backlog mutations.
 *
 * Uses PHP's flock() so the lock is automatically released when the
 * process exits or dies unexpectedly.
 */
final class BacklogMutationLock
{
    private const POLL_INTERVAL_US = 200_000;

    /** @var resource|false */
    private $handle = false;

    /**
     * BacklogMutationLock constructor.
     */
    public function __construct(
        private readonly string $lockPath,
        private readonly int $timeoutSeconds = 30
    ) {
    }

    /**
     * Returns the lock file path.
     */
    public function getLockPath(): string
    {
        return $this->lockPath;
    }

    /**
     * Acquires the exclusive lock, waiting up to $timeoutSeconds for it.
     *
     * @param callable|null $onWaiting Called once when the first wait cycle starts; receives no arguments.
     * @throws \RuntimeException when the lock cannot be acquired within the timeout.
     */
    public function acquire(?callable $onWaiting = null): void
    {
        $dir = dirname($this->lockPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create lock directory: %s', $dir));
        }

        $handle = fopen($this->lockPath, 'c');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot open lock file: %s', $this->lockPath));
        }

        $deadline = microtime(true) + $this->timeoutSeconds;
        $waited = false;

        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (!$waited) {
                $waited = true;
                if ($onWaiting !== null) {
                    ($onWaiting)();
                }
            }

            if (microtime(true) >= $deadline) {
                fclose($handle);

                throw new \RuntimeException(sprintf(
                    'Backlog mutation lock not available after %d seconds. Another backlog command may still be running.',
                    $this->timeoutSeconds,
                ));
            }

            usleep(self::POLL_INTERVAL_US);
        }

        $this->handle = $handle;
    }

    /**
     * Releases the exclusive lock.
     */
    public function release(): void
    {
        if ($this->handle === false) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = false;
    }
}
