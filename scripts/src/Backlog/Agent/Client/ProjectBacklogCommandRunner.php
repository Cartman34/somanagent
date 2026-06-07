<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Client;

use Sowapps\Toolkit\Client\ProjectScriptClient;
use Sowapps\Toolkit\Client\AppScript;
use Sowapps\SoManAgent\Script\Backlog\Agent\Exception\EntryNotReservableException;

/**
 * Delegates reviewer workflow transitions to backlog.php via ProjectScriptClient.
 *
 * Each call runs under the backlog global mutation lock, performing the same
 * revalidation as a manual reviewer invocation.
 */
final class ProjectBacklogCommandRunner implements BacklogCommandRunner
{
    private ProjectScriptClient $scriptClient;
    private string $projectRoot;

    /**
     * @param ProjectScriptClient $scriptClient Client used to invoke backlog.php
     * @param string $projectRoot Absolute path to WP; commands are executed from here
     */
    public function __construct(ProjectScriptClient $scriptClient, string $projectRoot)
    {
        $this->scriptClient = $scriptClient;
        $this->projectRoot = $projectRoot;
    }

    /**
     * {@inheritdoc}
     */
    public function reviewNext(string $reviewerCode, string $entryRef): void
    {
        [$code, $output] = $this->scriptClient->captureWithExitCodeWithEnv(
            AppScript::BACKLOG,
            ['SOMANAGER_ROLE' => 'reviewer', 'SOMANAGER_AGENT' => $reviewerCode],
            'review-next ' . escapeshellarg($entryRef),
            $this->projectRoot,
        );

        if ($code !== 0) {
            if (
                str_contains($output, 'is already in') ||
                str_contains($output, 'is not in') ||
                str_contains($output, 'No active entry matches reference')
            ) {
                throw new EntryNotReservableException($entryRef, $output);
            }

            throw new \RuntimeException(sprintf("review-next failed (exit %d): %s", $code, $output));
        }

        if ($output !== '') {
            echo $output . "\n";
        }
    }

    /**
     * {@inheritdoc}
     */
    public function reviewCancel(string $reviewerCode, string $entryRef): void
    {
        $this->scriptClient->runWithEnv(
            AppScript::BACKLOG,
            ['SOMANAGER_ROLE' => 'reviewer', 'SOMANAGER_AGENT' => $reviewerCode],
            'review-cancel ' . escapeshellarg($entryRef),
            $this->projectRoot,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function workStart(string $developerCode, string $entryRef): void
    {
        [$code, $output] = $this->scriptClient->captureWithExitCodeWithEnv(
            AppScript::BACKLOG,
            ['SOMANAGER_ROLE' => 'developer', 'SOMANAGER_AGENT' => $developerCode],
            'start ' . escapeshellarg($entryRef),
            $this->projectRoot,
        );

        if ($code !== 0) {
            if (str_contains($output, 'No queued task found for reference:')) {
                throw new EntryNotReservableException($entryRef, $output);
            }

            throw new \RuntimeException(sprintf("start failed (exit %d): %s", $code, $output));
        }

        if ($output !== '') {
            echo $output . "\n";
        }
    }

    /**
     * {@inheritdoc}
     */
    public function entryRelease(string $developerCode, string $entryRef): void
    {
        $this->scriptClient->runWithEnv(
            AppScript::BACKLOG,
            ['SOMANAGER_ROLE' => 'developer', 'SOMANAGER_AGENT' => $developerCode],
            'release ' . escapeshellarg($entryRef),
            $this->projectRoot,
        );
    }
}
