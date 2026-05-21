<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use SoManAgent\Script\GitHub\Enum\GitHubCommandName;
use SoManAgent\Script\Service\GitService;

/**
 * GitHub CLI helper script runner.
 *
 * Create PRs, merge, close, edit, list, view via the GitHub API.
 */
final class GitHubRunner extends AbstractScriptRunner
{
    private const NAME = 'github';

    private ?string $token = null;
    private ?string $repo = null;

    protected function getName(): string
    {
        return self::NAME;
    }

    protected function printHelp(): void
    {
        $this->printYamlHelp();
    }

    /**
     * Dispatches GitHub pull request flat commands after credentials are loaded.
     *
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        [$positional, $flags] = $this->parseArgs(array_values($args));
        $command = array_shift($positional) ?? '';

        if (isset($flags['help']) || isset($flags['h'])) {
            $this->printCommandHelp($command);

            return 0;
        }

        $this->configureExecutionModes($flags);

        if (!$this->dryRun) {
            $this->ensureCredentials();
        }

        try {
            match ($command) {
                GitHubCommandName::PR_CREATE->value     => $this->handleCreate($positional, $flags),
                GitHubCommandName::PR_MERGE->value      => $this->handleMerge($positional, $flags),
                GitHubCommandName::PR_CLOSE->value      => $this->handleClose($positional),
                GitHubCommandName::PR_EDIT->value       => $this->handleEdit($positional, $flags),
                GitHubCommandName::PR_LIST->value       => $this->handleList(),
                GitHubCommandName::PR_VIEW->value       => $this->handleView($positional),
                GitHubCommandName::PR_VIEW_STATE->value => $this->handleViewState($positional),
                default                             => throw new \RuntimeException(sprintf(
                    'Unknown command: %s. Available: %s',
                    $command,
                    implode(', ', $this->getRunnerHelp()->commandNames),
                )),
            };

            return 0;
        } catch (\RuntimeException $e) {
            $this->console->fail($e->getMessage());
        }
    }

    /**
     * @param list<string> $args
     * @param array<string, bool|string|array<bool|string>> $flags
     */
    private function handleCreate(array $args, array $flags): void
    {
        $title    = $this->getSingleOption($flags, 'title');
        $head     = $this->getSingleOption($flags, 'head');
        $base     = $this->getSingleOption($flags, 'base') ?? GitService::MAIN_BRANCH;
        $bodyFile = $this->getSingleOption($flags, BacklogCliOption::BODY_FILE->value);

        if (is_string($bodyFile)) {
            $body = $this->readBodyFile($bodyFile);
        } else {
            $body = $this->getSingleOption($flags, 'body') ?? '';
        }

        if (!$title) {
            throw new \RuntimeException('--title is required.');
        }

        if (!$head) {
            throw new \RuntimeException('--head is required.');
        }

        if ($this->dryRun) {
            $this->console->ok(sprintf('Dry-run: would create PR from %s to %s.', (string) $head, (string) $base));

            return;
        }

        $this->console->step("Creating PR: {$title}");
        $pr = $this->api('POST', '/pulls', [
            'title' => $title,
            'body'  => $body,
            'head'  => $head,
            'base'  => $base,
        ]);

        if (is_string($bodyFile)) {
            $this->deleteBodyFile($bodyFile);
        }

        $this->console->ok("PR #{$pr['number']} created: {$pr['html_url']}");
    }

    /**
     * @param list<string> $args
     * @param array<string, bool|string|array<bool|string>> $flags
     */
    private function handleMerge(array $args, array $flags): void
    {
        $number = (int) array_shift($args);
        $method = isset($flags['squash']) ? 'squash' : (isset($flags['rebase']) ? 'rebase' : 'merge');

        if (!$number) {
            throw new \RuntimeException('PR number is required.');
        }

        if ($this->dryRun) {
            $this->console->ok(sprintf('Dry-run: would merge PR #%d with %s.', $number, $method));

            return;
        }

        $this->console->step("Merging PR #{$number} ({$method})");
        $this->api('PUT', "/pulls/{$number}/merge", ['merge_method' => $method]);
        $this->console->ok("PR #{$number} merged.");
    }

    /**
     * @param list<string> $args
     */
    private function handleClose(array $args): void
    {
        $number = (int) array_shift($args);
        if (!$number) {
            throw new \RuntimeException('PR number is required.');
        }
        if ($this->dryRun) {
            $this->console->ok(sprintf('Dry-run: would close PR #%d.', $number));

            return;
        }
        $this->console->step("Closing PR #{$number}");
        $this->api('PATCH', "/pulls/{$number}", ['state' => 'closed']);
        $this->console->ok("PR #{$number} closed.");
    }

    /**
     * @param list<string> $args
     * @param array<string, bool|string|array<bool|string>> $flags
     */
    private function handleEdit(array $args, array $flags): void
    {
        $number = (int) array_shift($args);

        if (!$number) {
            throw new \RuntimeException('PR number is required.');
        }

        $patch = [];

        $title = $this->getSingleOption($flags, 'title');
        if ($title !== null) {
            $patch['title'] = $title;
        }

        $bodyFile = $this->getSingleOption($flags, BacklogCliOption::BODY_FILE->value);
        $body = $this->getSingleOption($flags, 'body');

        if (is_string($bodyFile)) {
            $patch['body'] = $this->readBodyFile($bodyFile);
        } elseif (is_string($body)) {
            $patch['body'] = $body;
        }

        if (empty($patch)) {
            throw new \RuntimeException('At least one of --title, --body, --body-file is required.');
        }

        if ($this->dryRun) {
            $this->console->ok(sprintf('Dry-run: would update PR #%d.', $number));

            return;
        }

        $this->console->step("Editing PR #{$number}");
        $pr = $this->api('PATCH', "/pulls/{$number}", $patch);

        if (is_string($bodyFile)) {
            $this->deleteBodyFile($bodyFile);
        }

        $this->console->ok("PR #{$number} updated: {$pr['html_url']}");
    }

    private function handleList(): void
    {
        if ($this->dryRun) {
            $this->console->ok('Dry-run: would list open PRs.');

            return;
        }

        $prs = $this->api('GET', '/pulls?state=open&per_page=20');
        if (empty($prs)) {
            $this->console->ok('No open PRs.');
        } else {
            foreach ($prs as $pr) {
                $this->console->line("  #{$pr['number']}  {$pr['title']}  [{$pr['head']['ref']} → {$pr['base']['ref']}]");
            }
        }
    }

    /**
     * @param list<string> $args
     */
    private function handleView(array $args): void
    {
        $number = (int) array_shift($args);
        if (!$number) {
            throw new \RuntimeException('PR number is required.');
        }
        if ($this->dryRun) {
            $this->console->ok(sprintf('Dry-run: would view PR #%d.', $number));

            return;
        }
        $pr = $this->api('GET', "/pulls/{$number}");
        $this->console->line("PR #{$pr['number']}: {$pr['title']}");
        $this->console->line("State  : {$pr['state']}");
        $this->console->line("Branch : {$pr['head']['ref']} → {$pr['base']['ref']}");
        $this->console->line("URL    : {$pr['html_url']}");
        $this->console->line("Body   :\n{$pr['body']}");
    }

    /**
     * @param list<string> $args
     */
    private function handleViewState(array $args): void
    {
        $number = (int) array_shift($args);
        if (!$number) {
            throw new \RuntimeException('PR number is required.');
        }
        if ($this->dryRun) {
            $this->console->ok(sprintf('Dry-run: would view state of PR #%d.', $number));

            return;
        }
        $pr = $this->api('GET', "/pulls/{$number}");
        if (($pr['merged'] ?? false) === true) {
            $this->console->line('merged');
        } elseif (($pr['state'] ?? '') === 'open') {
            $this->console->line('open');
        } else {
            $this->console->line('closed');
        }
    }

    /**
     * Print contextual help for a single PR command loaded from YAML.
     *
     * Delegates to CommandHelpService which throws a RuntimeException for unknown
     * commands, propagating exit code 1 via the handle() outer catch.
     */
    private function printCommandHelp(string $command): void
    {
        $this->printYamlCommandHelp($command);
    }

    /**
     * Performs a GitHub API request via curl.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $body = []): array
    {
        if ($method === '') {
            throw new \RuntimeException('GitHub API method cannot be empty.');
        }

        $url = "https://api.github.com/repos/{$this->repo}{$path}";
        $ch  = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize GitHub API request.');
        }
        $postFields = $body !== [] ? json_encode($body) : null;
        if ($postFields === false) {
            throw new \RuntimeException('Unable to encode GitHub API request body.');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$this->token}",
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: somanagent-script',
            'Content-Type: application/json',
        ]);
        if ($postFields !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        }
        $response = curl_exec($ch);
        if (!is_string($response)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('GitHub API transport error: ' . ($error !== '' ? $error : 'unknown curl error'));
        }

        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($response, true) ?? [];
        if (!is_array($data)) {
            $data = ['message' => $response];
        }
        if ($code >= 400) {
            $message = $data['message'] ?? $response;

            if (isset($data['errors']) && is_array($data['errors']) && $data['errors'] !== []) {
                $details = [];

                foreach ($data['errors'] as $error) {
                    if (!is_array($error)) {
                        $details[] = (string) $error;
                        continue;
                    }

                    $detailParts = [];

                    foreach (['resource', 'field', 'code', 'message'] as $key) {
                        if (isset($error[$key]) && $error[$key] !== '') {
                            $detailParts[] = sprintf('%s=%s', $key, (string) $error[$key]);
                        }
                    }

                    $details[] = implode(', ', $detailParts);
                }

                $message .= ' [' . implode(' | ', array_filter($details)) . ']';
            }

            throw new \RuntimeException("GitHub API error {$code}: {$message}");
        }
        /** @var array<string, mixed> $data */
        return $data;
    }

    private function readBodyFile(string $path): string
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("--body-file: file not found: {$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("--body-file: unable to read file: {$path}");
        }

        return $contents;
    }

    private function deleteBodyFile(string $path): void
    {
        if ($this->dryRun) {
            $this->logVerbose('[dry-run] Would delete body file: ' . $path);
            return;
        }

        if (file_exists($path) && !unlink($path)) {
            throw new \RuntimeException("--body-file: unable to delete file after successful request: {$path}");
        }
    }

    private function logVerbose(string $message): void
    {
        if ($this->verbose) {
            $this->console->info($message);
        }
    }

    /**
     * Load GITHUB_TOKEN from .env and detect repo from git remote.
     *
     * Called at the start of run() so that -h/--help can exit before this.
     */
    private function ensureCredentials(): void
    {
        if ($this->token !== null && $this->repo !== null) {
            return;
        }

        $envFile = "{$this->projectRoot}/.env";
        $token   = null;

        if (file_exists($envFile)) {
            $lines = file($envFile);
            if ($lines === false) {
                throw new \RuntimeException("Unable to read .env file: $envFile");
            }

            foreach ($lines as $line) {
                $line = trim($line);
                if (str_starts_with($line, 'GITHUB_TOKEN=')) {
                    $token = trim(substr($line, strlen('GITHUB_TOKEN=')));
                }
            }
        }

        if (empty($token)) {
            $this->console->fail("GITHUB_TOKEN not found in .env");
        }
        $this->token = $token;

        $remoteRaw = shell_exec('git remote get-url origin 2>/dev/null');
        $remote = is_string($remoteRaw) ? trim($remoteRaw) : '';
        $repo   = null;
        if (preg_match('#github\.com[:/](.+?)(?:\.git)?$#', $remote, $m)) {
            $repo = $m[1];
        }

        if (empty($repo)) {
            $this->console->fail("Could not detect GitHub repo from git remote origin.");
        }
        $this->repo = $repo;
    }
}
