<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * GitHub CLI helper script runner.
 *
 * Create PRs, merge, close, edit, list, view via the GitHub API.
 */
final class GitHubRunner extends AbstractScriptRunner
{
    private ?string $token = null;
    private ?string $repo = null;

    protected function getDescription(): string
    {
        return 'GitHub CLI helper — create PRs, merge, close, edit, list, view';
    }

    protected function getCommands(): array
    {
        return [
            ['name' => 'pr create', 'description' => 'Create a new pull request'],
            ['name' => 'pr merge', 'description' => 'Merge a pull request'],
            ['name' => 'pr close', 'description' => 'Close a pull request'],
            ['name' => 'pr edit', 'description' => 'Edit a pull request title or body'],
            ['name' => 'pr list', 'description' => 'List open pull requests'],
            ['name' => 'pr view', 'description' => 'View a pull request details'],
        ];
    }

    protected function getArguments(): array
    {
        return [
            ['name' => '<number>', 'description' => 'Pull request number (for merge, close, edit, view)'],
        ];
    }

    protected function getOptions(): array
    {
        return array_merge([
            ['name' => '--title', 'description' => 'PR title (create, edit)'],
            ['name' => '--head', 'description' => 'Source branch (create)'],
            ['name' => '--base', 'description' => 'Target branch, defaults to main (create)'],
            ['name' => '--body', 'description' => 'PR body text (create, edit)'],
            ['name' => '--body-file', 'description' => 'Path to a file for PR body (create, edit)'],
            ['name' => '--squash', 'description' => 'Squash merge (merge)'],
            ['name' => '--rebase', 'description' => 'Rebase merge (merge)'],
        ], $this->getExecutionModeOptions());
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/github.php pr create --title "Fix login" --head fix/login --body "Description..."',
            'php scripts/github.php pr create --title "Fix login" --head fix/login --body-file local/tmp/pr_body.md',
            'php scripts/github.php pr merge 42 --squash',
            'php scripts/github.php pr close 42',
            'php scripts/github.php pr edit 42 --title "Updated title"',
            'php scripts/github.php pr list',
            'php scripts/github.php pr view 42',
        ];
    }

    /**
     * Dispatches GitHub pull request subcommands after credentials are loaded.
     *
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        $command = array_shift($args) ?? '';
        $sub     = array_shift($args) ?? '';
        $flags   = $this->parseFlags($args);
        $this->configureExecutionModes($flags);

        if (!$this->dryRun) {
            $this->ensureCredentials();
        }

        try {
            if ($command !== 'pr') {
                throw new \RuntimeException("Unknown command: {$command}. Available: pr");
            }

            match ($sub) {
                'create' => $this->handleCreate($args, $flags),
                'merge'  => $this->handleMerge($args, $flags),
                'close'  => $this->handleClose($args),
                'edit'   => $this->handleEdit($args, $flags),
                'list'   => $this->handleList(),
                'view'   => $this->handleView($args),
                default  => throw new \RuntimeException("Unknown subcommand: pr {$sub}. Available: create, merge, close, edit, list, view"),
            };

            return 0;
        } catch (\RuntimeException $e) {
            $this->console->fail($e->getMessage());
        }
    }

    /**
     * @param array<string> $args
     */
    private function handleCreate(array $args, array $flags): void
    {
        $title    = $flags['title']     ?? null;
        $head     = $flags['head']      ?? null;
        $base     = $flags['base']      ?? 'main';
        $bodyFile = $flags['body-file'] ?? null;

        if ($bodyFile !== null) {
            $body = $this->readBodyFile($bodyFile);
        } else {
            $body = $flags['body'] ?? '';
        }

        if (!$title) {
            throw new \RuntimeException('--title is required.');
        }

        if (!$head) {
            throw new \RuntimeException('--head is required.');
        }

        if ($this->dryRun) {
            $this->console->ok(sprintf('Dry-run: would create PR from %s to %s.', $head, $base));

            return;
        }

        $this->console->step("Creating PR: {$title}");
        $pr = $this->api('POST', '/pulls', [
            'title' => $title,
            'body'  => $body,
            'head'  => $head,
            'base'  => $base,
        ]);

        if ($bodyFile !== null) {
            $this->deleteBodyFile($bodyFile);
        }

        $this->console->ok("PR #{$pr['number']} created: {$pr['html_url']}");
    }

    /**
     * @param array<string> $args
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
     * @param array<string> $args
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
     * @param array<string> $args
     */
    private function handleEdit(array $args, array $flags): void
    {
        $number = (int) array_shift($args);

        if (!$number) {
            throw new \RuntimeException('PR number is required.');
        }

        $patch = [];

        if (isset($flags['title'])) {
            $patch['title'] = $flags['title'];
        }

        if (isset($flags['body-file'])) {
            $patch['body'] = $this->readBodyFile((string) $flags['body-file']);
        } elseif (isset($flags['body'])) {
            $patch['body'] = $flags['body'];
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

        if (isset($flags['body-file'])) {
            $this->deleteBodyFile((string) $flags['body-file']);
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
     * @param array<string> $args
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
     * Parse --flag value pairs from remaining args.
     *
     * @param array<string> $args
     * @return array<string, mixed>
     */
    private function parseFlags(array $args): array
    {
        $flags = [];
        $i = 0;
        while ($i < count($args)) {
            $arg = $args[$i];
            if (str_starts_with($arg, '--')) {
                $key = ltrim($arg, '-');
                $val = $args[$i + 1] ?? true;
                if ($val !== true && str_starts_with((string) $val, '--')) {
                    $val = true;
                } else {
                    $i++;
                }
                $flags[$key] = $val;
            }
            $i++;
        }
        return $flags;
    }

    /**
     * Call the GitHub REST API.
     *
     * @return array<mixed>
     */
    private function api(string $method, string $path, array $body = []): array
    {
        $url = "https://api.github.com/repos/{$this->repo}{$path}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$this->token}",
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: somanagent-script',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $body ? json_encode($body) : null,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('GitHub API transport error: ' . ($error !== '' ? $error : 'unknown curl error'));
        }

        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($response, true) ?? [];
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
     * Load GITHUB_TOKEN from .env / .env.local and detect repo from git remote.
     *
     * .env.local takes priority over .env. Both files are read in order so that
     * a value set in .env.local overrides the generic default in .env.
     *
     * Called at the start of run() so that -h/--help can exit before this.
     */
    private function ensureCredentials(): void
    {
        if ($this->token !== null && $this->repo !== null) {
            return;
        }

        $token = null;

        foreach (["{$this->projectRoot}/.env", "{$this->projectRoot}/.env.local"] as $envFile) {
            if (!file_exists($envFile)) {
                continue;
            }
            foreach (file($envFile) as $line) {
                $line = trim($line);
                if (str_starts_with($line, 'GITHUB_TOKEN=')) {
                    $value = trim(substr($line, strlen('GITHUB_TOKEN=')));
                    if ($value !== '') {
                        $token = $value;
                    }
                }
            }
        }

        if (empty($token)) {
            $this->console->fail("GITHUB_TOKEN not found in .env or .env.local");
        }
        $this->token = $token;

        $remote = trim(shell_exec('git remote get-url origin 2>/dev/null') ?? '');
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
