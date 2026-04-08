<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\Agent;
use App\Enum\ConnectorType;
use App\Port\ConnectorInterface;
use App\Service\AgentService;
use App\Service\ConnectorRegistry;
use App\ValueObject\ConnectorConfig;
use App\ValueObject\ConnectorRequest;
use App\ValueObject\Prompt;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends low-level requests directly through one connector without project or agent context.
 */
#[AsCommand(
    name: 'somanagent:connector:send',
    description: 'Sends a direct request through one connector',
)]
final class ConnectorSendCommand extends Command
{
    /**
     * Wires the connector registry and the optional agent configuration source.
     */
    public function __construct(
        private readonly ConnectorRegistry $connectorRegistry,
        private readonly AgentService $agentService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('connector', InputArgument::REQUIRED, 'Connector value such as claude_api, claude_cli, codex_api, codex_cli, or opencode_cli')
            ->addOption('message', null, InputOption::VALUE_REQUIRED, 'Message to send')
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'Optional agent UUID used only as a configuration source')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model override')
            ->addOption('working-directory', null, InputOption::VALUE_REQUIRED, 'Working directory passed to the connector', ConnectorRequest::DEFAULT_WORKING_DIRECTORY)
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Outputs the raw connector response instead of the normalized content; incompatible with --conversation and --test')
            ->addOption('conversation', null, InputOption::VALUE_NONE, 'Starts a local interactive conversation loop')
            ->addOption('test', null, InputOption::VALUE_NONE, 'Runs the generic "Say OK" connection test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $connectorValue = (string) $input->getArgument('connector');
        try {
            $connectorType = ConnectorType::from($connectorValue);
        } catch (\ValueError) {
            $io->error(sprintf('Unknown connector: %s', $connectorValue));
            return Command::INVALID;
        }

        $connector = $this->connectorRegistry->getFor($connectorType);
        $agent = $this->resolveAgent($input, $io);
        if ($agent === false) {
            return Command::FAILURE;
        }

        $config = $this->resolveConfig($input, $agent instanceof Agent ? $agent : null);
        $workingDirectory = (string) $input->getOption('working-directory');
        $raw = (bool) $input->getOption('raw');

        if ($raw && (bool) $input->getOption('conversation')) {
            $io->error('--raw is incompatible with --conversation.');
            return Command::INVALID;
        }

        if ($raw && (bool) $input->getOption('test')) {
            $io->error('--raw is incompatible with --test.');
            return Command::INVALID;
        }

        if ((bool) $input->getOption('test')) {
            return $this->runSingleRequest($io, $connectorType, $connector, $config, $workingDirectory, 'Say OK', true, false);
        }

        if ((bool) $input->getOption('conversation')) {
            return $this->runConversation($input, $output, $io, $connectorType, $connector, $config, $workingDirectory);
        }

        $message = trim((string) $input->getOption('message'));
        if ($message === '') {
            $io->error('Provide --message, or use --conversation, or use --test.');
            return Command::INVALID;
        }

        return $this->runSingleRequest($io, $connectorType, $connector, $config, $workingDirectory, $message, false, $raw);
    }

    private function resolveAgent(InputInterface $input, SymfonyStyle $io): Agent|false|null
    {
        $agentId = trim((string) $input->getOption('agent'));
        if ($agentId === '') {
            return null;
        }

        $agent = $this->agentService->findById($agentId);
        if ($agent === null) {
            $io->error(sprintf('Agent not found: %s', $agentId));
            return false;
        }

        return $agent;
    }

    private function resolveConfig(InputInterface $input, ?Agent $agent): ConnectorConfig
    {
        $baseConfig = $agent?->getConnectorConfig() ?? ConnectorConfig::default();
        $rawModel = trim((string) $input->getOption('model'));

        return new ConnectorConfig(
            model: $rawModel !== '' ? $rawModel : $baseConfig->model,
            maxTokens: $baseConfig->maxTokens,
            temperature: $baseConfig->temperature,
            timeout: $baseConfig->timeout,
            extraParams: $baseConfig->extraParams,
        );
    }

    private function runSingleRequest(
        SymfonyStyle $io,
        ConnectorType $connectorType,
        ConnectorInterface $connector,
        ConnectorConfig $config,
        string $workingDirectory,
        string $message,
        bool $testMode,
        bool $raw,
    ): int {
        try {
            $response = $connector->sendRequest(
                ConnectorRequest::fromPrompt(Prompt::create('', $message), $workingDirectory),
                $testMode ? new ConnectorConfig(model: $config->model, maxTokens: 64, timeout: 30, temperature: $config->temperature, extraParams: $config->extraParams) : $config,
            );
        } catch (\Throwable $throwable) {
            $io->error($throwable->getMessage());
            return Command::FAILURE;
        }

        if ($raw) {
            $io->writeln($response->rawOutput ?? '[no raw output available]');
            return Command::SUCCESS;
        }

        if ($testMode) {
            $io->success(sprintf('%s connection test succeeded.', $connectorType->label()));
        } else {
            $io->title(sprintf('SoManAgent — %s', $connectorType->label()));
        }

        $io->definitionList(
            ['Connector' => $connectorType->value],
            ['Model' => $config->model ?? '(connector default)'],
            ['Session' => $response->sessionId ?? '(none)'],
            ['Duration' => sprintf('%d ms', (int) $response->durationMs)],
        );
        $io->writeln($response->content !== '' ? $response->content : '[empty response]');

        return Command::SUCCESS;
    }

    private function runConversation(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        ConnectorType $connectorType,
        ConnectorInterface $connector,
        ConnectorConfig $config,
        string $workingDirectory,
    ): int {
        $io->title(sprintf('SoManAgent — Conversation with %s', $connectorType->label()));
        $io->writeln('Type `/exit` to stop.');

        $sessionId = null;
        while (true) {
            $output->write('> ');
            $line = fgets(STDIN);
            if ($line === false) {
                break;
            }

            $message = trim($line);
            if ($message === '') {
                continue;
            }

            if ($message === '/exit') {
                break;
            }

            try {
                $response = $connector->sendRequest(
                    new ConnectorRequest(
                        prompt: Prompt::create('', $message),
                        workingDirectory: $workingDirectory,
                        sessionId: $sessionId,
                    ),
                    $config,
                );
                $sessionId = $response->sessionId ?? $sessionId;
                $io->writeln($response->content !== '' ? $response->content : '[empty response]');
            } catch (\Throwable $throwable) {
                $io->error($throwable->getMessage());
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
