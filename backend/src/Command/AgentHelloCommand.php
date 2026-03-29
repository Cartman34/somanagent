<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AgentService;
use App\Service\ChatService;
use App\Service\ProjectService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'somanagent:agent:hello',
    description: 'Sends a test message to an agent in a project context',
)]
final class AgentHelloCommand extends Command
{
    public function __construct(
        private readonly ProjectService $projectService,
        private readonly AgentService $agentService,
        private readonly ChatService $chatService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('projectId', InputArgument::REQUIRED, 'Project UUID')
            ->addArgument('agentId', InputArgument::REQUIRED, 'Agent UUID')
            ->addOption('message', null, InputOption::VALUE_REQUIRED, 'Message to send', 'hello');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projectId = (string) $input->getArgument('projectId');
        $agentId   = (string) $input->getArgument('agentId');
        $message   = trim((string) $input->getOption('message'));

        $project = $this->projectService->findById($projectId);
        $agent   = $this->agentService->findById($agentId);

        if ($project === null) {
            $io->error(sprintf('Project not found: %s', $projectId));
            return Command::FAILURE;
        }

        if ($agent === null) {
            $io->error(sprintf('Agent not found: %s', $agentId));
            return Command::FAILURE;
        }

        if ($message === '') {
            $io->error('Message cannot be empty.');
            return Command::INVALID;
        }

        $exchange = $this->chatService->sendAndReceive($project, $agent, $message);
        $reply    = $exchange['agent'];

        $io->title('SoManAgent - Agent Test');
        $io->definitionList(
            ['Project' => sprintf('%s (%s)', $project->getName(), $projectId)],
            ['Agent' => sprintf('%s (%s)', $agent->getName(), $agentId)],
            ['Connector' => $agent->getConnector()->value],
            ['Message' => $message],
            ['Exchange' => $reply->getExchangeId()],
        );

        $metadata = $reply->getMetadata() ?? [];
        if ($metadata !== []) {
            $io->section('Metadata');
            foreach ($metadata as $key => $value) {
                $io->writeln(sprintf('%s: %s', $key, is_scalar($value) ? (string) $value : json_encode($value)));
            }
        }

        $io->section($reply->isError() ? 'Agent Error' : 'Agent Response');
        $io->writeln($reply->getContent() !== '' ? $reply->getContent() : '[empty response]');

        return $reply->isError() ? Command::FAILURE : Command::SUCCESS;
    }
}
