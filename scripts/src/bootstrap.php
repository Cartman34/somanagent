<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 *
 * Shared bootstrap for every PHP entrypoint under scripts/.
 *
 * Normal mode:
 * - load scripts/vendor/autoload.php
 * - make the SoManAgent\Script\* classes available to the entrypoint
 *
 * Recovery mode:
 * - on a fresh checkout, scripts/vendor/autoload.php may be missing
 * - in that case, most scripts cannot continue because every runner and helper
 *   class depends on the Composer autoloader provided by scripts/vendor/
 * - only entrypoints that explicitly opt in through the global
 *   $GLOBALS['somanagent_scripts_allow_autoinstall'] flag are allowed to
 *   bootstrap the scripts dependencies automatically
 *
 * Important constraint:
 * - the auto-install path must delegate to scripts/scripts-install.php
 * - that file must stay standalone and must not depend on this bootstrap,
 *   otherwise the project would reintroduce the same circular dependency:
 *   no autoload => no bootstrap => no way to install autoload
 */

declare(strict_types=1);

/**
 * Root directory of the scripts toolchain.
 *
 * This file lives in scripts/src/, so dirname(__DIR__) resolves to scripts/.
 */
$scriptsDir = dirname(__DIR__);

/**
 * Composer autoloader for the scripts toolchain.
 *
 * Every normal scripts entrypoint depends on this file before it can load any
 * runner or support class from scripts/src/.
 */
$vendorAutoload = $scriptsDir . '/vendor/autoload.php';

/**
 * Explicit opt-in used by a caller such as scripts/setup.php.
 *
 * When true, bootstrap may attempt to install scripts dependencies
 * automatically before requiring the Composer autoloader.
 * When false, bootstrap must fail fast with a clear user-facing instruction.
 */
$allowAutoInstall = ($GLOBALS['somanagent_scripts_allow_autoinstall'] ?? false) === true;

/**
 * Fresh checkout / uninitialized scripts environment.
 *
 * Most entrypoints stop here and print remediation instructions.
 * A small number of bootstrap-authorized entrypoints may recover by launching
 * the standalone scripts installer, then re-checking that autoload.php exists
 * before continuing.
 */
if (!is_file($vendorAutoload)) {
    if ($allowAutoInstall) {
        /**
         * Standalone recovery helper.
         *
         * Do not replace this with a runner-based script: runners themselves are
         * autoloaded and therefore unavailable until scripts/vendor/autoload.php
         * exists.
         */
        $installScript = $scriptsDir . '/scripts-install.php';
        $phpBinary = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $command = sprintf('%s %s 2>&1', escapeshellarg($phpBinary), escapeshellarg($installScript));
        passthru($command, $exitCode);

        /**
         * Keep the failure path explicit.
         *
         * Successful installer execution is not enough on its own; the expected
         * autoload file must exist before the bootstrap can continue safely.
         */
        if ($exitCode !== 0 || !is_file($vendorAutoload)) {
            fwrite(STDERR, "Unable to install scripts dependencies automatically.\n");
            fwrite(STDERR, "Run manually: php scripts/scripts-install.php\n");
            exit($exitCode !== 0 ? $exitCode : 1);
        }
    } else {
        /**
         * Default path for every scripts entrypoint except explicit bootstrap
         * callers such as setup.php.
         */
        fwrite(STDERR, "Missing scripts dependencies.\n");
        fwrite(STDERR, "Run one of:\n");
        fwrite(STDERR, "  php scripts/scripts-install.php  # install scripts dependencies only\n");
        fwrite(STDERR, "  php scripts/setup.php            # full project setup\n");
        exit(1);
    }
}

/**
 * Normal scripts runtime starts here.
 */
require_once $vendorAutoload;
