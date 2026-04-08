<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Command;

use App\Service\ConnectorRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists registered connectors with their runtime status and capabilities.
 */
#[AsCommand(
    name: 'somanagent:agent:adapters',
    description: 'Lists registered agent adapters with their status',
)]
final class ListAgentAdaptersCommand extends Command
{
    /**
     * Wires the connector registry used to render the runtime summary table.
     */
    public function __construct(private readonly ConnectorRegistry $connectorRegistry)
    {
        parent::__construct();
    }

    /**
     * Renders one table row per registered connector with health and discovery capability information.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $descriptors = $this->connectorRegistry->describeAll();

        $io->title('SoManAgent — Connectors');
        $io->table(
            ['Connector', 'Label', 'Connector', 'Health', 'Auth', 'Method', 'Usage Limits', 'Model Discovery'],
            array_map(
                static fn ($descriptor): array => [
                    $descriptor->connector->value,
                    $descriptor->connector->label(),
                    $descriptor->connectorClass,
                    $descriptor->isOverallHealthy() ? 'healthy' : 'degraded',
                    $descriptor->authentication?->status ?? 'n/a',
                    $descriptor->authentication?->method ?? 'n/a',
                    match (true) {
                        $descriptor->authentication?->supportsAccountUsage !== true => 'n/a',
                        $descriptor->authentication?->usesAccountUsage === true => 'yes',
                        default => 'no',
                    },
                    $descriptor->supportsModelDiscovery ? 'yes' : 'no',
                ],
                $descriptors,
            ),
        );

        foreach ($descriptors as $descriptor) {
            if ($descriptor->authentication?->summary === null) {
                continue;
            }

            $io->writeln(sprintf(
                '%s: %s',
                $descriptor->connector->value,
                $descriptor->authentication->summary,
            ));
        }

        return Command::SUCCESS;
    }
}
