<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Command;

use App\Enum\ConnectorType;
use App\Service\AgentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI command to change the connector type for one or all agents.
 */
#[AsCommand(
    name: 'somanagent:agent:connector',
    description: 'Changes the connector for one agent or for every agent',
)]
final class AgentConnectorCommand extends Command
{
    /**
     * Initializes the command with the agent service used to update connectors.
     */
    public function __construct(private readonly AgentService $agentService)
    {
        parent::__construct();
    }

    /**
     * Declares the command arguments and options.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('connector', InputArgument::REQUIRED, 'claude_api or claude_cli')
            ->addArgument('agentId', InputArgument::OPTIONAL, 'UUID of the agent to update')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Applies the change to every agent');
    }

    /**
     * Switches the connector for one agent or for every agent.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $connectorValue = (string) $input->getArgument('connector');
        $agentId        = $input->getArgument('agentId');
        $applyToAll     = (bool) $input->getOption('all');

        try {
            $connector = ConnectorType::from($connectorValue);
        } catch (\ValueError) {
            $io->error(sprintf('Invalid connector: %s. Accepted values: claude_api, claude_cli.', $connectorValue));
            return Command::INVALID;
        }

        if ($applyToAll) {
            $count = $this->agentService->setConnectorForAll($connector);
            $io->success(sprintf('%d agent(s) switched to %s.', $count, $connector->value));
            return Command::SUCCESS;
        }

        if (!is_string($agentId) || $agentId === '') {
            $io->error('Provide an agentId or use --all.');
            return Command::INVALID;
        }

        $agent = $this->agentService->findById($agentId);
        if ($agent === null) {
            $io->error(sprintf('Agent not found: %s', $agentId));
            return Command::FAILURE;
        }

        $this->agentService->setConnector($agent, $connector);

        $io->success(sprintf(
            'Agent %s (%s) switched to %s.',
            $agent->getName(),
            $agentId,
            $connector->value,
        ));

        return Command::SUCCESS;
    }
}
