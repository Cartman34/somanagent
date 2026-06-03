<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Service;

use Sowapps\SoManAgent\Script\Backlog\Agent\Client\ProcessRunner;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncher;

/**
 * Detects upstream CLI flag removals before they break a real agent launch.
 *
 * For each registered AgentClientLauncher, the validator looks up the binary's
 * `--help` text and asserts that every flag declared by `requiredCliFlags()` is
 * still advertised. A missing flag means the binary CLI has evolved upstream
 * and the launcher must be updated; a missing binary is skipped without
 * failing so CI runners without the optional client installed still pass.
 *
 * The detection method is intentionally conservative: the flag spelling must
 * appear as a delimited token in the help output. False negatives (a flag
 * still accepted but no longer documented) are acceptable; false positives
 * (a flag string appearing inside an unrelated sentence) are guarded against
 * with a word-boundary regex.
 */
final class LauncherFlagValidator
{
    /**
     * @param ProcessRunner $processRunner Runner used to invoke `<bin> --help` and capture its combined stdout/stderr
     */
    public function __construct(private ProcessRunner $processRunner) {}

    /**
     * Validates the CLI flags of every launcher passed in.
     *
     * @param list<AgentClientLauncher> $launchers
     * @return list<LauncherFlagReport>
     */
    public function validate(array $launchers): array
    {
        $reports = [];
        foreach ($launchers as $launcher) {
            $reports[] = $this->validateOne($launcher);
        }

        return $reports;
    }

    private function validateOne(AgentClientLauncher $launcher): LauncherFlagReport
    {
        $client = $launcher->client()->value;

        if (!$launcher->isAvailable()) {
            return new LauncherFlagReport($client, true, []);
        }

        $help = $this->captureHelp($client);
        $checks = [];
        foreach ($launcher->requiredCliFlags() as $flag) {
            $checks[] = new LauncherFlagCheck($flag, $this->helpContainsFlag($help, $flag));
        }

        return new LauncherFlagReport($client, false, $checks);
    }

    /**
     * Returns the combined stdout+stderr of `<client> --help`, or empty string when capture fails.
     *
     * `$client` is the AgentClient enum string — one of the fixed values claude/codex/opencode/gemini —
     * so it is safe to embed directly without shell escaping. Help text often lands on stderr for
     * short-help CLIs, so stderr is folded into stdout via `2>&1`.
     */
    private function captureHelp(string $client): string
    {
        $command = $client . ' --help 2>&1';
        $output = $this->processRunner->output($command);

        return $output ?? '';
    }

    /**
     * Returns true when `$flag` appears as a delimited token in `$help`.
     *
     * The flag is anchored at the start of a line or after whitespace/comma, and
     * is followed by a delimiter (whitespace, end-of-line, `=`, or `,`) so that
     * short flags like `-C` do not match an unrelated word containing `-C`.
     */
    private function helpContainsFlag(string $help, string $flag): bool
    {
        $pattern = '/(^|[\s,])' . preg_quote($flag, '/') . '($|[\s,=])/m';

        return preg_match($pattern, $help) === 1;
    }
}
