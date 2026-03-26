<?php

declare(strict_types=1);

/**
 * Displays the overall project status: Docker, migrations, schema, git.
 *
 * Usage: php scripts/claude/status.php
 *
 * Requires Docker Compose for migration and schema checks.
 */

$root = realpath(__DIR__ . '/../../');
chdir($root);

$ok   = fn(string $msg) => print("  [OK]   {$msg}\n");
$warn = fn(string $msg) => print("  [WARN] {$msg}\n");
$info = fn(string $msg) => print("  [INFO] {$msg}\n");
$fail = fn(string $msg) => print("  [FAIL] {$msg}\n");

// ── 1. Docker Compose ─────────────────────────────────────────────────────────

echo "\n── Docker ──\n";

$psRaw = shell_exec('docker compose ps --format "table {{.Name}}\t{{.Status}}\t{{.Service}}" 2>&1');

if ($psRaw === null || str_contains($psRaw, 'error') || str_contains($psRaw, 'Error')) {
    $fail("Docker Compose unavailable or not started.");
    $dockerUp = false;
} else {
    $lines    = array_filter(explode("\n", trim($psRaw)));
    $dockerUp = true;
    foreach ($lines as $line) {
        if (str_starts_with($line, 'NAME')) {
            continue; // skip header
        }
        $running = str_contains(strtolower($line), 'running') || str_contains(strtolower($line), 'up');
        $running ? $ok($line) : $warn($line);
        if (!$running) $dockerUp = false;
    }
}

// ── 2. Doctrine migrations ────────────────────────────────────────────────────

echo "\n── Migrations ──\n";

if (!$dockerUp) {
    $info("Docker unavailable — skipping migration check.");
} else {
    $migStatus = shell_exec(
        'docker compose exec -T php php bin/console doctrine:migrations:status --no-ansi 2>&1'
    );

    if ($migStatus === null) {
        $fail("Could not run doctrine:migrations:status.");
    } else {
        foreach (explode("\n", $migStatus) as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (str_contains($line, 'New Migrations') || str_contains($line, 'Executed Migrations')) {
                if (preg_match('/(\d+)/', $line, $m)) {
                    $count = (int) $m[1];
                    if (str_contains($line, 'New') && $count > 0) {
                        $warn("{$count} pending migration(s) → php scripts/migrate.php");
                    } elseif (str_contains($line, 'New') && $count === 0) {
                        $ok("No pending migrations.");
                    } else {
                        $info($line);
                    }
                }
            }
        }
    }
}

// ── 3. Doctrine schema ────────────────────────────────────────────────────────

echo "\n── DB Schema ──\n";

if (!$dockerUp) {
    $info("Docker unavailable — skipping schema check.");
} else {
    $schemaStatus = shell_exec(
        'docker compose exec -T php php bin/console doctrine:schema:validate --no-ansi 2>&1'
    );

    if ($schemaStatus === null) {
        $fail("Could not run doctrine:schema:validate.");
    } else {
        $inSync = str_contains($schemaStatus, '[OK]') && !str_contains($schemaStatus, 'differences');
        if ($inSync) {
            $ok("DB schema is in sync with entities.");
        } else {
            $warn("Differences detected between entities and DB.");
            foreach (explode("\n", $schemaStatus) as $line) {
                $line = trim($line);
                if (!empty($line) && (str_contains($line, 'ERROR') || str_contains($line, 'differ') || str_contains($line, 'not'))) {
                    echo "    {$line}\n";
                }
            }
        }
    }
}

// ── 4. Git status ─────────────────────────────────────────────────────────────

echo "\n── Git ──\n";

$gitStatus = shell_exec('git status --short 2>&1');
if (empty(trim($gitStatus ?? ''))) {
    $ok("Working tree clean.");
} else {
    $count = count(array_filter(explode("\n", trim($gitStatus))));
    $warn("{$count} modified / untracked file(s).");
    foreach (explode("\n", trim($gitStatus)) as $line) {
        if (!empty(trim($line))) {
            echo "    {$line}\n";
        }
    }
}

echo "\n";
