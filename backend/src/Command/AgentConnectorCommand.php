<?php

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

#[AsCommand(
    name: 'somanagent:agent:connector',
    description: 'Change le connecteur d un agent precis ou de tous les agents',
)]
final class AgentConnectorCommand extends Command
{
    public function __construct(private readonly AgentService $agentService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('connector', InputArgument::REQUIRED, 'claude_api ou claude_cli')
            ->addArgument('agentId', InputArgument::OPTIONAL, 'UUID de l agent a modifier')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Applique le changement a tous les agents');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $connectorValue = (string) $input->getArgument('connector');
        $agentId        = $input->getArgument('agentId');
        $applyToAll     = (bool) $input->getOption('all');

        try {
            $connector = ConnectorType::from($connectorValue);
        } catch (\ValueError) {
            $io->error(sprintf('Connecteur invalide: %s. Valeurs acceptees: claude_api, claude_cli.', $connectorValue));
            return Command::INVALID;
        }

        if ($applyToAll) {
            $count = $this->agentService->setConnectorForAll($connector);
            $io->success(sprintf('%d agent(s) bascules vers %s.', $count, $connector->value));
            return Command::SUCCESS;
        }

        if (!is_string($agentId) || $agentId === '') {
            $io->error('Precisez un agentId ou utilisez --all.');
            return Command::INVALID;
        }

        $agent = $this->agentService->findById($agentId);
        if ($agent === null) {
            $io->error(sprintf('Agent introuvable: %s', $agentId));
            return Command::FAILURE;
        }

        $this->agentService->setConnector($agent, $connector);

        $io->success(sprintf(
            'Agent %s (%s) bascule vers %s.',
            $agent->getName(),
            $agentId,
            $connector->value,
        ));

        return Command::SUCCESS;
    }
}
