<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Command;

use App\Enum\ConnectorType;
use App\Service\ConnectorRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * CLI command to diagnose authentication environment for CLI connectors.
 *
 * Reports process identity, binary availability, auth file permissions, and
 * registry auth status for each CLI connector. Run this inside the container
 * (via scripts/console.php) to compare with dashboard results and detect
 * FPM vs CLI divergence.
 */
#[AsCommand(
    name: 'somanagent:auth:diagnose',
    description: 'Diagnoses binary availability, auth file permissions, and auth status for CLI connectors',
)]
final class AuthDiagnoseCommand extends Command
{
    /**
     * Known binary path and auth file locations per CLI connector.
     *
     * binary    — absolute path when the binary is installed at a fixed location.
     * binaryShell — login-shell command used to locate the binary when PATH-dependent.
     * authHome  — container directory expected to hold the auth data.
     * authPaths — specific paths checked for existence and permissions.
     *
     * @var array<string, array{binary: string|null, binaryShell: string|null, authHome: string, authPaths: list<string>}>
     */
    private const CLI_CONNECTORS = [
        'claude_cli' => [
            'binary'      => '/usr/local/bin/claude',
            'binaryShell' => null,
            'authHome'    => '/claude-home',
            'authPaths'   => ['/claude-home/.claude', '/claude-home/.claude.json'],
        ],
        'codex_cli' => [
            'binary'      => null,
            'binaryShell' => 'which codex 2>/dev/null',
            'authHome'    => '/codex-home',
            'authPaths'   => ['/codex-home/.codex'],
        ],
        'opencode_cli' => [
            'binary'      => null,
            'binaryShell' => 'which opencode 2>/dev/null',
            'authHome'    => '/opencode-home',
            'authPaths'   => ['/opencode-home/.local'],
        ],
    ];

    /**
     * Initializes the command with the auth registry used to fetch live auth status per connector.
     */
    public function __construct(private readonly ConnectorRegistry $connectorRegistry)
    {
        parent::__construct();
    }

    /**
     * Declares the optional connector filter argument.
     */
    protected function configure(): void
    {
        $this->addArgument(
            'connector',
            InputArgument::OPTIONAL,
            sprintf('Connector to inspect (%s); defaults to all CLI connectors', implode(', ', array_keys(self::CLI_CONNECTORS))),
        );
    }

    /**
     * Prints process identity, binary status, auth file details, and registry auth status for each CLI connector.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SoManAgent — Auth Diagnostics');

        $this->printProcessIdentity($io);

        $filterConnector = $input->getArgument('connector');

        $connectorsToCheck = array_filter(
            self::CLI_CONNECTORS,
            static fn (string $key): bool => $filterConnector === null || $key === $filterConnector,
            ARRAY_FILTER_USE_KEY,
        );

        if ($connectorsToCheck === [] && $filterConnector !== null) {
            $io->error(sprintf('Unknown or non-CLI connector: %s. Valid: %s', $filterConnector, implode(', ', array_keys(self::CLI_CONNECTORS))));
            return Command::INVALID;
        }

        foreach ($connectorsToCheck as $connectorValue => $info) {
            $connector = ConnectorType::from($connectorValue);
            $io->section(sprintf('Connector: %s (%s)', $connector->label(), $connector->value));

            $this->printBinaryStatus($io, $info);
            $this->printAuthPaths($io, $info['authPaths']);
            $this->printAuthStatus($io, $connector);
        }

        return Command::SUCCESS;
    }

    /**
     * Prints the effective process user and HOME environment variable.
     */
    private function printProcessIdentity(SymfonyStyle $io): void
    {
        $io->section('Process identity');

        $idProcess = new Process(['id'], '/var/www/backend', null, null, 5);
        $idProcess->run();
        $io->writeln('  id: ' . ($idProcess->isSuccessful() ? trim($idProcess->getOutput()) : '(id unavailable)'));
        $io->writeln('  HOME env: ' . (getenv('HOME') ?: '(not set)'));
    }

    /**
     * Checks whether the connector binary is available and prints its resolved path.
     *
     * @param array{binary: string|null, binaryShell: string|null, authHome: string, authPaths: list<string>} $info
     */
    private function printBinaryStatus(SymfonyStyle $io, array $info): void
    {
        $fixed = $info['binary'];

        if ($fixed !== null) {
            $exists     = file_exists($fixed);
            $executable = $exists && is_executable($fixed);
            $statusTag  = $executable ? 'info' : ($exists ? 'comment' : 'error');
            $statusText = $executable ? 'ok' : ($exists ? 'not executable' : 'not found');
            $io->writeln(sprintf('  Binary: %s — <%s>%s</%s>', $fixed, $statusTag, $statusText, $statusTag));
            return;
        }

        $shellCmd = $info['binaryShell'] ?? null;

        if ($shellCmd === null) {
            $io->writeln('  Binary: (no binary check configured)');
            return;
        }

        $proc = new Process(['sh', '-lc', $shellCmd], '/var/www/backend', null, null, 5);
        $proc->run();
        $resolved = trim($proc->getOutput());

        if ($resolved !== '') {
            $io->writeln(sprintf('  Binary: <info>%s</info>', $resolved));
        } else {
            $io->writeln('  Binary: <error>not found in login-shell PATH</error>');
        }
    }

    /**
     * Prints existence, permissions, and owner for each auth path (file or directory).
     *
     * @param list<string> $paths
     */
    private function printAuthPaths(SymfonyStyle $io, array $paths): void
    {
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                $io->writeln(sprintf('  Path %s: <error>not found</error>', $path));
                continue;
            }

            $perms = substr(sprintf('%o', fileperms($path)), -4);
            $owner = '?';

            if (function_exists('posix_getpwuid') && function_exists('fileowner')) {
                $ownerInfo = posix_getpwuid((int) fileowner($path));
                $owner     = is_array($ownerInfo) ? ($ownerInfo['name'] ?? '?') : '?';
            }

            if (is_dir($path)) {
                $entries = scandir($path);
                $count   = $entries !== false ? max(0, count($entries) - 2) : 0;
                $io->writeln(sprintf('  Dir  %s: perms=%s owner=%s entries=%d', $path, $perms, $owner, $count));
            } else {
                $size = filesize($path);
                $io->writeln(sprintf('  File %s: perms=%s owner=%s size=%d', $path, $perms, $owner, $size !== false ? $size : 0));
            }
        }
    }

    /**
     * Fetches and prints the auth registry status for the connector.
     */
    private function printAuthStatus(SymfonyStyle $io, ConnectorType $connector): void
    {
        $status = $this->connectorRegistry->getFor($connector)->getAuthenticationStatus();

        $healthy = $status->isHealthy();
        $tag     = $healthy ? 'info' : 'error';
        $label   = $healthy ? 'ok' : 'degraded';

        $io->writeln(sprintf('  Auth status:   <%s>%s</%s>', $tag, $label, $tag));
        $io->writeln(sprintf('  Authenticated: %s', $status->authenticated ? 'yes' : 'no'));
        $io->writeln(sprintf('  Status code:   %s', $status->status));

        if ($status->method !== null) {
            $io->writeln(sprintf('  Method:        %s', $status->method));
        }

        if ($status->summary !== null) {
            $io->writeln(sprintf('  Summary:       %s', $status->summary));
        }

        if ($status->error !== null) {
            $io->writeln(sprintf('  Error:         <error>%s</error>', $status->error));
        }

        if ($status->fixCommand !== null) {
            $io->writeln(sprintf('  Fix:           %s', $status->fixCommand));
        }
    }
}
