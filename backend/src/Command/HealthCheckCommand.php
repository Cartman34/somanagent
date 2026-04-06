<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Command;

use App\Service\AgentPortRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI command to check the health of AI and VCS connectors.
 */
#[AsCommand(name: 'somanagent:health', description: 'Checks the health of AI and VCS connectors')]
class HealthCheckCommand extends Command
{
    /**
     * Initializes the command with the connector registry.
     */
    public function __construct(private readonly AgentPortRegistry $registry)
    {
        parent::__construct();
    }

    /**
     * Prints the health status of every registered connector.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $io->title('SoManAgent — Connector Check');

        $results = $this->registry->healthCheckAll();
        $allOk   = true;

        foreach ($results as $connector => $ok) {
            if ($ok) {
                $io->writeln("  <info>✓</info> {$connector}");
            } else {
                $io->writeln("  <error>✗</error> {$connector}");
                $allOk = false;
            }
        }

        if ($allOk) {
            $io->success('All connectors are operational.');
            return Command::SUCCESS;
        }

        $io->warning('Some connectors are unreachable.');
        return Command::FAILURE;
    }
}
