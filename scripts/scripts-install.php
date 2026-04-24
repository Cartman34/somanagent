#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 *
 * Rules:
 *
 * - this file must stay standalone and must not use the scripts runner stack
 * - do not move this file under scripts/src/Runner/
 * - do not require scripts/src/bootstrap.php here
 * - do not import SoManAgent\Script\... classes here
 * - this script exists because scripts/src/bootstrap.php normally depends on
 *   scripts/vendor/autoload.php, which may be missing on a fresh checkout
 */
// Description: Install the local Composer dependencies required by scripts/
// Usage: php scripts/scripts-install.php
// Usage: php scripts/scripts-install.php --update

declare(strict_types=1);

$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    fwrite(STDERR, "Unable to resolve the project root.\n");
    exit(1);
}

$scriptsDir = $projectRoot . '/scripts';
$vendorAutoload = $scriptsDir . '/vendor/autoload.php';
$updateMode = in_array('--update', array_slice($argv ?? [], 1), true);

if (!$updateMode && is_file($vendorAutoload)) {
    fwrite(STDOUT, "scripts dependencies are already installed.\n");
    exit(0);
}

$composerBinary = null;
$composerCandidates = [
    'composer',
    $projectRoot . '/composer.phar',
    $scriptsDir . '/composer.phar',
];
foreach ($composerCandidates as $candidate) {
    $versionOutput = [];
    $versionCode = 0;
    $command = is_file($candidate)
        ? sprintf('%s %s --version 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($candidate))
        : sprintf('%s --version 2>&1', escapeshellcmd($candidate));
    exec($command, $versionOutput, $versionCode);
    if ($versionCode === 0) {
        $composerBinary = is_file($candidate)
            ? sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($candidate))
            : escapeshellcmd($candidate);
        break;
    }
}

if ($composerBinary === null) {
    fwrite(STDERR, "Composer is required to install scripts dependencies.\n");
    fwrite(STDERR, "Install Composer, then run: php scripts/scripts-install.php\n");
    exit(1);
}

$composerAction = $updateMode ? 'update' : 'install';
$command = sprintf('%s %s --working-dir=%s 2>&1', $composerBinary, $composerAction, escapeshellarg($scriptsDir));
passthru($command, $exitCode);

if ($exitCode !== 0) {
    fwrite(STDERR, sprintf("Failed to %s scripts dependencies.\n", $composerAction));
    fwrite(STDERR, "Retry manually with: php scripts/scripts-install.php\n");
    exit($exitCode);
}

if (!is_file($vendorAutoload)) {
    fwrite(STDERR, "Composer finished but scripts/vendor/autoload.php is still missing.\n");
    fwrite(STDERR, "Retry manually with: php scripts/scripts-install.php\n");
    exit(1);
}

if ($updateMode) {
    fwrite(STDOUT, "scripts dependencies updated.\n");
} else {
    fwrite(STDOUT, "scripts dependencies installed.\n");
}
exit(0);
