#!/usr/bin/env php
<?php
// Description: GitHub CLI helper — create PRs, merge, list, view
// Usage: php scripts/github.php pr create --title "..." --head <branch> --body-file /tmp/pr_body.md [--base main]
// Usage: php scripts/github.php pr create --title "..." --head <branch> --body "..." [--base main]
// Usage: php scripts/github.php pr merge <number> [--squash]
// Usage: php scripts/github.php pr list
// Usage: php scripts/github.php pr view <number>

require_once __DIR__ . '/src/Application.php';

try {
    $app = new Application();
    $app->boot();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "\n❌ " . $e->getMessage() . "\n\n");
    exit(1);
}

$c = $app->console;

// Load token from .env
$envFile = dirname(__DIR__) . '/.env';
$token   = null;
$repo    = null;

if (file_exists($envFile)) {
    foreach (file($envFile) as $line) {
        $line = trim($line);
        if (str_starts_with($line, 'GITHUB_TOKEN=')) {
            $token = trim(substr($line, strlen('GITHUB_TOKEN=')));
        }
    }
}

if (empty($token)) {
    $c->fail('GITHUB_TOKEN not found in .env');
    exit(1);
}

// Detect repo from git remote
$remote = trim(shell_exec('git remote get-url origin 2>/dev/null') ?? '');
if (preg_match('#github\.com[:/](.+?)(?:\.git)?$#', $remote, $m)) {
    $repo = $m[1];
}

if (empty($repo)) {
    $c->fail('Could not detect GitHub repo from git remote origin.');
    exit(1);
}

// Parse arguments
$args    = array_slice($argv, 1);
$command = array_shift($args) ?? '';  // e.g. "pr"
$sub     = array_shift($args) ?? '';  // e.g. "create"

/**
 * GitHub API helper.
 */
$api = function (string $method, string $path, array $body = []) use ($token, $repo): array {
    $url = "https://api.github.com/repos/{$repo}{$path}";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
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
};

/**
 * Parse --flag value pairs from remaining args.
 */
$parseFlags = function (array $args): array {
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
};

try {
    if ($command === 'pr') {

        if ($sub === 'create') {
            $flags    = $parseFlags($args);
            $title    = $flags['title']     ?? null;
            $head     = $flags['head']      ?? null;
            $base     = $flags['base']      ?? 'main';
            $bodyFile = $flags['body-file'] ?? null;

            if ($bodyFile !== null) {
                if (!file_exists($bodyFile)) {
                    $c->fail("--body-file: file not found: {$bodyFile}");
                    exit(1);
                }
                $body = file_get_contents($bodyFile);
                unlink($bodyFile);
            } else {
                $body = $flags['body'] ?? '';
            }

            if (!$title) {
                $c->fail('--title is required.');
                exit(1);
            }

            if (!$head) {
                $c->fail('--head is required.');
                exit(1);
            }

            $c->step("Creating PR: {$title}");
            $pr = $api('POST', '/pulls', [
                'title' => $title,
                'body'  => $body,
                'head'  => $head,
                'base'  => $base,
            ]);
            $c->ok("PR #{$pr['number']} created: {$pr['html_url']}");

        } elseif ($sub === 'merge') {
            $number = (int) array_shift($args);
            $flags  = $parseFlags($args);
            $method = isset($flags['squash']) ? 'squash' : (isset($flags['rebase']) ? 'rebase' : 'merge');

            if (!$number) {
                $c->fail('PR number is required.');
                exit(1);
            }

            $c->step("Merging PR #{$number} ({$method})");
            $api('PUT', "/pulls/{$number}/merge", ['merge_method' => $method]);
            $c->ok("PR #{$number} merged.");

        } elseif ($sub === 'list') {
            $prs = $api('GET', '/pulls?state=open&per_page=20');
            if (empty($prs)) {
                $c->ok('No open PRs.');
            } else {
                foreach ($prs as $pr) {
                    $c->line("  #{$pr['number']}  {$pr['title']}  [{$pr['head']['ref']} → {$pr['base']['ref']}]");
                }
            }

        } elseif ($sub === 'view') {
            $number = (int) array_shift($args);
            if (!$number) {
                $c->fail('PR number is required.');
                exit(1);
            }
            $pr = $api('GET', "/pulls/{$number}");
            $c->line("PR #{$pr['number']}: {$pr['title']}");
            $c->line("State  : {$pr['state']}");
            $c->line("Branch : {$pr['head']['ref']} → {$pr['base']['ref']}");
            $c->line("URL    : {$pr['html_url']}");
            $c->line("Body   :\n{$pr['body']}");

        } else {
            $c->fail("Unknown subcommand: pr {$sub}. Available: create, merge, list, view");
            exit(1);
        }

    } else {
        $c->fail("Unknown command: {$command}. Available: pr");
        exit(1);
    }

} catch (\RuntimeException $e) {
    $c->fail($e->getMessage());
    exit(1);
}
