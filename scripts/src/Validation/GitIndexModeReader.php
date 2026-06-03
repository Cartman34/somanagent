<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Validation;

use Sowapps\SoManAgent\Script\Validation\IndexModeReader;

/**
 * Reads file modes recorded in the git index (`100755` vs `100644`).
 *
 * The filesystem `is_executable()` check is unreliable on WSL when
 * `core.filemode = false`: the bit appears set on disk yet the index still
 * stores `100644`, which is the state a fresh clone would receive. The
 * persistent invariant for shipping a runnable script is the *index* mode,
 * so callers that care about reproducibility must consult this reader rather
 * than relying solely on filesystem permissions.
 */
final class GitIndexModeReader implements IndexModeReader
{
    /**
     * @param string $projectRoot Absolute path to the repository working directory
     */
    public function __construct(private string $projectRoot) {}

    /**
     * Returns the index mode for each file that git tracks.
     *
     * Files not present in the index (untracked, missing, outside the repository)
     * are omitted from the result. Caller code typically reads the entry with
     * `$modes[$file] ?? null` and treats `null` as "no opinion".
     *
     * @param list<string> $files Paths to inspect. Absolute and project-relative both work; absolute paths are normalised to project-relative for the git query.
     * @return array<string, string> Map keyed by the same path string the caller passed in; values are six-digit octal modes like `100755` or `100644`.
     */
    public function readModes(array $files): array
    {
        if ($files === []) {
            return [];
        }

        $prefix = rtrim($this->projectRoot, '/') . '/';
        $relativeByOriginal = [];
        foreach ($files as $file) {
            $relativeByOriginal[$file] = str_starts_with($file, $prefix)
                ? substr($file, strlen($prefix))
                : $file;
        }

        $relatives = array_values(array_unique($relativeByOriginal));
        $command = $this->buildCommand($relatives);
        $output = $this->execCommand($command);
        if ($output === null) {
            return [];
        }

        $modesByRelative = $this->parseLsFiles($output);

        $modes = [];
        foreach ($relativeByOriginal as $original => $relative) {
            if (isset($modesByRelative[$relative])) {
                $modes[$original] = $modesByRelative[$relative];
            }
        }

        return $modes;
    }

    /**
     * Builds a `git -C <root> ls-files --stage -- <files>` command line.
     *
     * @param list<string> $relativeFiles
     */
    private function buildCommand(array $relativeFiles): string
    {
        $args = array_map('escapeshellarg', $relativeFiles);

        return 'git -C ' . escapeshellarg($this->projectRoot) . ' ls-files --stage -- ' . implode(' ', $args);
    }

    /**
     * Executes the git command and returns stdout, or null when git is unavailable or the command failed.
     */
    private function execCommand(string $command): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = proc_open($command, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($exit !== 0 || !is_string($stdout)) {
            return null;
        }

        return $stdout;
    }

    /**
     * Parses `git ls-files --stage` output into a map keyed by repository-relative path.
     *
     * Line format: `<mode> <sha> <stage>\t<path>` — for example `100755 abc... 0\tscripts/foo.php`.
     *
     * @return array<string, string>
     */
    private function parseLsFiles(string $output): array
    {
        $modes = [];
        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(\d{6})\s+[0-9a-f]+\s+\d+\t(.+)$/', $line, $matches) !== 1) {
                continue;
            }
            $modes[$matches[2]] = $matches[1];
        }

        return $modes;
    }
}
