<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Service;

/**
 * Stops agent sessions after a successful merge.
 *
 * Called at the end of performMerge in both BacklogFeatureMergeCommand and
 * BacklogFeatureTaskMergeCommand. Stops the developer session synchronously and,
 * when the caller is one of the sessions being stopped, schedules its own stop as
 * a detached sub-process to avoid self-suicide mid-command.
 */
final class PostMergeSessionStopper
{
    private BacklogPresenter $presenter;

    private string $projectRoot;

    /**
     * @param BacklogPresenter $presenter
     * @param string $projectRoot
     */
    public function __construct(BacklogPresenter $presenter, string $projectRoot)
    {
        $this->presenter = $presenter;
        $this->projectRoot = $projectRoot;
    }

    /**
     * Stops all sessions involved in a merge.
     *
     * Sessions that are not the caller are stopped synchronously. The caller's
     * own session is stopped after a 3-second delay via a detached subprocess.
     *
     * @param ?string $devCode    Agent code of the developer, or null when absent.
     * @param ?string $reviewerCode Agent code of the reviewer, or null when absent.
     */
    public function stopSessions(?string $devCode, ?string $reviewerCode): void
    {
        $codes = array_values(array_unique(array_filter([$devCode, $reviewerCode])));
        if ($codes === []) {
            return;
        }

        $callerCode = trim((string) getenv('SOMANAGER_AGENT'));
        $callerCode = $callerCode !== '' ? $callerCode : null;

        $hasSelf = $callerCode !== null && in_array($callerCode, $codes, true);
        $suffix = $hasSelf ? ' (self-stop in ~3s)' : '';
        $this->presenter->displayLine(sprintf('Auto-stopping sessions: %s%s', implode(', ', $codes), $suffix));

        foreach ($codes as $code) {
            if ($code === $callerCode) {
                $this->stopDeferred($code);
            } else {
                $this->stopSynchronous($code);
            }
        }
    }

    private function stopSynchronous(string $code): void
    {
        $phpBin = PHP_BINARY;
        $scriptPath = $this->projectRoot . '/scripts/backlog-agent.php';
        $cmd = sprintf('%s %s stop --code=%s 2>&1', escapeshellarg($phpBin), escapeshellarg($scriptPath), escapeshellarg($code));

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            $this->presenter->displayLine(sprintf(
                'Warning: auto-stop for %s failed (exit %d): %s',
                $code,
                $returnCode,
                implode(' ', $output),
            ));
        }
    }

    private function stopDeferred(string $code): void
    {
        $phpBin = PHP_BINARY;
        $scriptPath = $this->projectRoot . '/scripts/backlog-agent.php';
        $logFile = $this->projectRoot . '/local/backlog/backlog.log';

        $innerCmd = sprintf(
            'sleep 3; %s %s stop --code=%s >> %s 2>&1',
            escapeshellarg($phpBin),
            escapeshellarg($scriptPath),
            escapeshellarg($code),
            escapeshellarg($logFile),
        );

        shell_exec('setsid sh -c ' . escapeshellarg($innerCmd) . ' &');
    }
}
