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
        return [
            ['name' => '--title', 'description' => 'PR title (create, edit)'],
            ['name' => '--head', 'description' => 'Source branch (create)'],
            ['name' => '--base', 'description' => 'Target branch, defaults to main (create)'],
            ['name' => '--body', 'description' => 'PR body text (create, edit)'],
            ['name' => '--body-file', 'description' => 'Path to a file for PR body (create, edit)'],
            ['name' => '--squash', 'description' => 'Squash merge (merge)'],
            ['name' => '--rebase', 'description' => 'Rebase merge (merge)'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/github.php pr create --title "Fix login" --head fix/login --body "Description..."',
            'php scripts/github.php pr create --title "Fix login" --head fix/login --body-file /tmp/pr_body.md',
            'php scripts/github.php pr merge 42 --squash',
            'php scripts/github.php pr close 42',
            'php scripts/github.php pr edit 42 --title "Updated title"',
            'php scripts/github.php pr list',
            'php scripts/github.php pr view 42',
        ];
    }

    public function run(array $args): int
    {
        $this->ensureCredentials();

        $command = array_shift($args) ?? '';
        $sub     = array_shift($args) ?? '';

        try {
            if ($command !== 'pr') {
                throw new \RuntimeException("Unknown command: {$command}. Available: pr");
            }

            match ($sub) {
                'create' => $this->handleCreate($args),
                'merge'  => $this->handleMerge($args),
                'close'  => $this->handleClose($args),
                'edit'   => $this->handleEdit($args),
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
    private function handleCreate(array $args): void
    {
        $flags    = $this->parseFlags($args);
        $title    = $flags['title']     ?? null;
        $head     = $flags['head']      ?? null;
        $base     = $flags['base']      ?? 'main';
        $bodyFile = $flags['body-file'] ?? null;

        if ($bodyFile !== null) {
            if (!file_exists($bodyFile)) {
                throw new \RuntimeException("--body-file: file not found: {$bodyFile}");
            }
            $body = file_get_contents($bodyFile);
            unlink($bodyFile);
        } else {
            $body = $flags['body'] ?? '';
        }

        if (!$title) {
            throw new \RuntimeException('--title is required.');
        }

        if (!$head) {
            throw new \RuntimeException('--head is required.');
        }

        $this->console->step("Creating PR: {$title}");
        $pr = $this->api('POST', '/pulls', [
            'title' => $title,
            'body'  => $body,
            'head'  => $head,
            'base'  => $base,
        ]);
        $this->console->ok("PR #{$pr['number']} created: {$pr['html_url']}");
    }

    /**
     * @param array<string> $args
     */
    private function handleMerge(array $args): void
    {
        $number = (int) array_shift($args);
        $flags  = $this->parseFlags($args);
        $method = isset($flags['squash']) ? 'squash' : (isset($flags['rebase']) ? 'rebase' : 'merge');

        if (!$number) {
            throw new \RuntimeException('PR number is required.');
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
        $this->console->step("Closing PR #{$number}");
        $this->api('PATCH', "/pulls/{$number}", ['state' => 'closed']);
        $this->console->ok("PR #{$number} closed.");
    }

    /**
     * @param array<string> $args
     */
    private function handleEdit(array $args): void
    {
        $number = (int) array_shift($args);
        $flags  = $this->parseFlags($args);

        if (!$number) {
            throw new \RuntimeException('PR number is required.');
        }

        $patch = [];

        if (isset($flags['title'])) {
            $patch['title'] = $flags['title'];
        }

        if (isset($flags['body-file'])) {
            if (!file_exists($flags['body-file'])) {
                throw new \RuntimeException("--body-file: file not found: {$flags['body-file']}");
            }
            $patch['body'] = file_get_contents($flags['body-file']);
            unlink($flags['body-file']);
        } elseif (isset($flags['body'])) {
            $patch['body'] = $flags['body'];
        }

        if (empty($patch)) {
            throw new \RuntimeException('At least one of --title, --body, --body-file is required.');
        }

        $this->console->step("Editing PR #{$number}");
        $pr = $this->api('PATCH', "/pulls/{$number}", $patch);
        $this->console->ok("PR #{$number} updated: {$pr['html_url']}");
    }

    private function handleList(): void
    {
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
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($response, true) ?? [];
        if ($code >= 400) {
            throw new \RuntimeException("GitHub API error {$code}: " . ($data['message'] ?? $response));
        }
        return $data;
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
            foreach (file($envFile) as $line) {
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
