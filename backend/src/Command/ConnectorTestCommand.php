<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Command;

use App\Enum\ConnectorType;
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
 * CLI command to send a minimal test prompt directly through a connector.
 *
 * Tests the low-level sendRequest() path without any project, agent, or database context.
 * Useful to verify that the connector binary, auth, and model resolution work end-to-end
 * before running real tasks. See somanagent:agent:hello for a full-stack test with project context.
 *
 * When no model is specified, the connector uses its own default (CLI connectors read it from their config).
 * Use --model to override when needed.
 */
#[AsCommand(
    name: 'somanagent:connector:test',
    description: 'Sends a minimal test prompt directly through a connector adapter',
)]
final class ConnectorTestCommand extends Command
{
    /**
     * Initializes the command with the adapter registry used to resolve connectors.
     */
    public function __construct(private readonly ConnectorRegistry $registry)
    {
        parent::__construct();
    }

    /**
     * Declares the connector argument and optional model / message overrides.
     */
    protected function configure(): void
    {
        $connectorValues = implode(', ', array_map(
            static fn (ConnectorType $t): string => $t->value,
            ConnectorType::cases(),
        ));

        $this
            ->addArgument('connector', InputArgument::REQUIRED, sprintf('Connector to test (%s)', $connectorValues))
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model to use (optional — CLI connectors use their configured default when omitted)')
            ->addOption('message', null, InputOption::VALUE_REQUIRED, 'Prompt message', 'Say OK');
    }

    /**
     * Resolves the adapter, sends the prompt, and prints latency, token usage, and response excerpt.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $connectorValue = (string) $input->getArgument('connector');

        try {
            $connector = ConnectorType::from($connectorValue);
        } catch (\ValueError) {
            $io->error(sprintf('Unknown connector: %s', $connectorValue));
            return Command::INVALID;
        }

        $rawModel = trim((string) $input->getOption('model'));
        $model = $rawModel !== '' ? $rawModel : null;
        $message = trim((string) $input->getOption('message'));

        if ($message === '') {
            $io->error('Message cannot be empty.');
            return Command::INVALID;
        }

        $io->title(sprintf('SoManAgent — Connector Test: %s', $connector->label()));
        $io->definitionList(
            ['Connector' => $connector->value],
            ['Model'     => $model ?? '(connector default)'],
            ['Message'   => $message],
        );

        $adapter = $this->registry->getFor($connector);
        $request = ConnectorRequest::fromPrompt(Prompt::create('', $message), ConnectorRequest::DEFAULT_WORKING_DIRECTORY);
        $config  = new ConnectorConfig(model: $model, maxTokens: 64, timeout: 30);

        $io->section('Running...');

        try {
            $response = $adapter->sendRequest($request, $config);
        } catch (\Throwable $e) {
            $io->error(sprintf('Connector error: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        $io->success(sprintf('Response received in %d ms', (int) $response->durationMs));
        $io->definitionList(
            ['Input tokens'  => $response->inputTokens],
            ['Output tokens' => $response->outputTokens],
            ['Duration'      => sprintf('%d ms', (int) $response->durationMs)],
        );
        $io->section('Response');
        $io->writeln($response->content !== '' ? $response->content : '[empty response]');

        return Command::SUCCESS;
    }
}
